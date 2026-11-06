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
			'enabled'               => true,
			'format_mode'           => 'auto',
			'max_width'             => 2560,
			'max_height'            => 2560,
			'quality'               => 85,
			'convert_when_useful'   => true,
			'show_toasts'           => true,
			'toast_optimizing'      => '',
			'toast_optimized'       => '',
			'toast_fallback'        => '',
			'toast_dismiss'         => '',
			'detect_conflicts'      => true,
			'preserve_transparency' => true,
			'process_frontend'      => true,
			'process_admin'         => true,
			'wasm_encoders'         => false,
			'server_side_fallback'  => false,
			'server_side_only'      => false,
		];
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
			return $this->coerce(array_merge($defaults, $stored));
		}

		return $this->coerce(array_merge($defaults, $stored, $value));
	}

	/**
	 * @param array<string, mixed> $merged
	 * @return array<string, mixed>
	 */
	private function coerce(array $merged): array {
		$defaults = $this->get_defaults();
		$out      = $defaults;

		$out['enabled']               = ! empty($merged['enabled']);
		$out['convert_when_useful']   = ! empty($merged['convert_when_useful']);
		$out['show_toasts']           = ! empty($merged['show_toasts']);
		$out['detect_conflicts']      = ! empty($merged['detect_conflicts']);
		$out['preserve_transparency'] = ! empty($merged['preserve_transparency']);
		$out['process_frontend']      = ! empty($merged['process_frontend']);
		$out['process_admin']         = ! empty($merged['process_admin']);
		$out['wasm_encoders']         = ! empty($merged['wasm_encoders']);
		$out['server_side_fallback']  = ! empty($merged['server_side_fallback']);
		$out['server_side_only']      = ! empty($merged['server_side_only']);

		$mode = isset($merged['format_mode']) ? (string) $merged['format_mode'] : 'auto';
		$out['format_mode'] = in_array($mode, ['auto', 'webp', 'jpeg'], true) ? $mode : 'auto';

		$out['max_width']  = $this->sanitize_dimension($merged['max_width'] ?? null, (int) $defaults['max_width']);
		$out['max_height'] = $this->sanitize_dimension($merged['max_height'] ?? null, (int) $defaults['max_height']);

		$q = isset($merged['quality']) ? (int) $merged['quality'] : (int) $defaults['quality'];
		$out['quality'] = min(100, max(1, $q));

		$out['toast_optimizing'] = $this->sanitize_toast_text($merged['toast_optimizing'] ?? null);
		$out['toast_optimized']  = $this->sanitize_toast_text($merged['toast_optimized'] ?? null);
		$out['toast_fallback']   = $this->sanitize_toast_text($merged['toast_fallback'] ?? null);
		$out['toast_dismiss']    = $this->sanitize_toast_text($merged['toast_dismiss'] ?? null);

		return $out;
	}

	private function sanitize_toast_text(mixed $raw): string {
		if ($raw === null || ! is_string($raw)) {
			return '';
		}
		$s = sanitize_textarea_field($raw);
		if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($s) > 500) {
			return mb_substr($s, 0, 500);
		}
		if (strlen($s) > 500) {
			return substr($s, 0, 500);
		}

		return $s;
	}

	private function sanitize_dimension(mixed $raw, int $fallback): int {
		if ($raw === null || $raw === '') {
			return $fallback;
		}
		$n = (int) $raw;
		if ($n < 1) {
			return $fallback;
		}
		return min(8192, $n);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option(self::OPTION_NAME, []);
		if (! is_array($stored)) {
			return $this->get_defaults();
		}

		return $this->coerce(array_merge($this->get_defaults(), $stored));
	}
}
