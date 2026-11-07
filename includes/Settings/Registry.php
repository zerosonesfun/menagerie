<?php
/**
 * Settings option name, defaults, and sanitization.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Settings;

if (! defined('ABSPATH')) {
	exit;
}

final class Registry {
	public const OPTION_NAME = 'menagerie_settings';

	public const SETTINGS_GROUP = 'menagerie';

	public function get_option_name(): string {
		return self::OPTION_NAME;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_defaults(): array {
		return [
			'output_format'          => 'keep',
			'jpeg_companion'         => true,
			'jpeg_companion_quality' => 75,
			'jpeg_companion_bg'      => '#ffffff',
			'jpeg_companion_max'     => 2560,
			'jpeg_companion_sizes'   => true,
			'picture_fallback'       => true,
			'og_jpeg_companion'      => true,
			'converter_quality'      => 85,
			'convert_when_useful'    => true,
			'show_toasts_admin'      => true,
			'show_toasts_frontend'   => true,
			'toast_converted_avif'   => '',
			'toast_fallback_webp'    => '',
			'toast_converted_webp'   => '',
			'toast_failed_keep'      => '',
			'toast_companion_ok'     => '',
			'toast_dismiss'          => '',
		];
	}

	/**
	 * Default toast copy (translated when language packs exist).
	 *
	 * @return array<string, string>
	 */
	public function get_toast_defaults(): array {
		return [
			'toast_converted_avif' => __('Image converted to AVIF successfully and uploaded.', 'menagerie'),
			'toast_fallback_webp'  => __('Image couldn\'t be converted to AVIF; converted to WebP instead and uploaded.', 'menagerie'),
			'toast_converted_webp' => __('Image converted to WebP successfully and uploaded.', 'menagerie'),
			'toast_failed_keep'    => __('Image couldn\'t be converted to the selected format; original uploaded.', 'menagerie'),
			'toast_companion_ok'   => __('JPEG fallback companion created.', 'menagerie'),
			'toast_dismiss'        => __('Dismiss notification', 'menagerie'),
		];
	}

	/**
	 * Resolved toast strings (custom override or translated default).
	 *
	 * @return array<string, string>
	 */
	public function get_toast_strings(): array {
		$settings = $this->get();
		$defaults = $this->get_toast_defaults();
		$out      = [];
		foreach ($defaults as $key => $default) {
			$custom = isset($settings[ $key ]) ? trim((string) $settings[ $key ]) : '';
			$out[ $key ] = $custom !== '' ? $custom : $default;
		}
		return $out;
	}

	public function register(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [$this, 'sanitize'],
				'default'           => $this->get_defaults(),
			]
		);
	}

	/**
	 * @param mixed $value Raw option value from Settings API.
	 * @return array<string, mixed>
	 */
	public function sanitize($value): array {
		$defaults = $this->get_defaults();
		$stored   = get_option(self::OPTION_NAME, []);
		if (! is_array($stored)) {
			$stored = [];
		}
		if (! is_array($value)) {
			return $this->coerce($this->merge_with_legacy($defaults, $stored));
		}

		return $this->coerce($this->merge_with_legacy($defaults, $stored, $value));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option(self::OPTION_NAME, []);
		if (! is_array($stored)) {
			$stored = [];
		}

		return $this->coerce($this->merge_with_legacy($this->get_defaults(), $stored));
	}

	/**
	 * @param array<string, mixed> $defaults
	 * @param array<string, mixed> $stored
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function merge_with_legacy(array $defaults, array $stored, array $incoming = []): array {
		$merged = array_merge($defaults, $stored, $incoming);
		if (! array_key_exists('converter_quality', $stored) && ! array_key_exists('converter_quality', $incoming)) {
			if (array_key_exists('quality', $incoming)) {
				$merged['converter_quality'] = $incoming['quality'];
			} elseif (array_key_exists('quality', $stored)) {
				$merged['converter_quality'] = $stored['quality'];
			}
		}
		return $merged;
	}

	/**
	 * @param array<string, mixed> $merged
	 * @return array<string, mixed>
	 */
	private function coerce(array $merged): array {
		$defaults = $this->get_defaults();
		$out      = $defaults;

		$format = isset($merged['output_format']) ? (string) $merged['output_format'] : (string) $defaults['output_format'];
		$out['output_format'] = in_array($format, [ 'keep', 'webp', 'avif' ], true)
			? $format
			: (string) $defaults['output_format'];

		$out['jpeg_companion']       = ! empty($merged['jpeg_companion']);
		$out['jpeg_companion_sizes'] = ! empty($merged['jpeg_companion_sizes']);
		$out['picture_fallback']     = ! empty($merged['picture_fallback']);
		$out['og_jpeg_companion']    = ! empty($merged['og_jpeg_companion']);
		$out['convert_when_useful']  = ! empty($merged['convert_when_useful']);
		$out['show_toasts_admin']    = ! empty($merged['show_toasts_admin']);
		$out['show_toasts_frontend'] = ! empty($merged['show_toasts_frontend']);

		$jq = isset($merged['jpeg_companion_quality']) ? (int) $merged['jpeg_companion_quality'] : (int) $defaults['jpeg_companion_quality'];
		$out['jpeg_companion_quality'] = min(100, max(1, $jq));

		$out['jpeg_companion_bg'] = $this->sanitize_hex_color_value(
			$merged['jpeg_companion_bg'] ?? null,
			(string) $defaults['jpeg_companion_bg']
		);

		$out['jpeg_companion_max'] = $this->sanitize_dimension(
			$merged['jpeg_companion_max'] ?? null,
			(int) $defaults['jpeg_companion_max']
		);

		$cq = isset($merged['converter_quality']) ? (int) $merged['converter_quality'] : (int) $defaults['converter_quality'];
		$out['converter_quality'] = min(100, max(1, $cq));

		foreach (array_keys($this->get_toast_defaults()) as $toast_key) {
			$raw = isset($merged[ $toast_key ]) ? (string) $merged[ $toast_key ] : '';
			$out[ $toast_key ] = $this->sanitize_toast_text($raw);
		}

		return $out;
	}

	private function sanitize_toast_text(string $raw): string {
		$raw = wp_strip_all_tags($raw);
		$raw = trim($raw);
		if (strlen($raw) > 500) {
			$raw = substr($raw, 0, 500);
		}
		return $raw;
	}

	private function sanitize_hex_color_value(mixed $raw, string $fallback): string {
		if (! is_string($raw) || $raw === '') {
			return $fallback;
		}
		$sanitized = sanitize_hex_color($raw);
		if (! is_string($sanitized) || $sanitized === '') {
			return $fallback;
		}
		if (strlen($sanitized) === 4) {
			$sanitized = '#' . $sanitized[1] . $sanitized[1] . $sanitized[2] . $sanitized[2] . $sanitized[3] . $sanitized[3];
		}
		return $sanitized;
	}

	private function sanitize_dimension(mixed $raw, int $fallback): int {
		$n = is_numeric($raw) ? (int) $raw : $fallback;
		return min(8192, max(1, $n));
	}
}
