<?php
/**
 * Enqueues scripts and styles with filemtime versions.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Assets;

use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class AssetLoader {
	private const SCRIPT_HANDLE = 'menagerie-optimizer';

	private const STYLE_HANDLE = 'menagerie-toast';

	private const SETTINGS_STYLE_HANDLE = 'menagerie-admin-settings';

	public function __construct(
		private Registry $registry
	) {
		add_action('admin_init', [$this, 'register_plupload_defaults'], 1);
		add_filter('script_loader_tag', [$this, 'script_type_module'], 10, 3);
	}

	/**
	 * ES module bundle (Vite dist) requires type="module" for import.meta and workers.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 */
	public function script_type_module(string $tag, string $handle, string $src): string {
		if ($handle !== self::SCRIPT_HANDLE) {
			return $tag;
		}
		if (str_contains($src, '/assets/js/dist/')) {
			return str_replace('<script ', '<script type="module" ', $tag);
		}

		return $tag;
	}

	/**
	 * Ensures Plupload can carry menagerie_meta (populated per file in JS).
	 */
	public function register_plupload_defaults(): void {
		$settings = $this->registry->get();
		if (! empty($settings['server_side_only'])) {
			return;
		}
		if (empty($settings['enabled']) || empty($settings['process_admin'])) {
			return;
		}
		if (! current_user_can('upload_files')) {
			return;
		}
		add_filter('plupload_default_settings', [$this, 'filter_plupload_defaults']);
	}

	/**
	 * @param array<string, mixed> $plupload
	 * @return array<string, mixed>
	 */
	public function filter_plupload_defaults(array $plupload): array {
		if (! isset($plupload['multipart_params']) || ! is_array($plupload['multipart_params'])) {
			$plupload['multipart_params'] = [];
		}
		$plupload['multipart_params']['menagerie_meta'] = '';
		return $plupload;
	}

	public function enqueue_admin(): void {
		if ($this->is_settings_page() && current_user_can('manage_options')) {
			$this->enqueue_admin_settings_style();
		}

		$settings = $this->registry->get();
		if (empty($settings['enabled']) && empty($settings['server_side_only'])) {
			return;
		}

		if (! empty($settings['server_side_only'])) {
			return;
		}

		if ($this->is_settings_page()) {
			return;
		}

		if (empty($settings['process_admin']) || ! current_user_can('upload_files')) {
			return;
		}

		if (! $this->should_load_admin_uploader()) {
			return;
		}

		$this->enqueue_optimizer_script($settings, true);
	}

	public function enqueue_frontend(): void {
		if (is_admin()) {
			return;
		}

		$settings = $this->registry->get();
		if (empty($settings['enabled']) && empty($settings['server_side_only'])) {
			return;
		}
		if (! empty($settings['server_side_only'])) {
			return;
		}
		if (empty($settings['process_frontend'])) {
			return;
		}

		/**
		 * Filter whether Menagerie should enqueue the front-end optimizer script.
		 *
		 * @param bool $enqueue Default true when client optimization is enabled for the front end.
		 */
		if (apply_filters('menagerie_should_enqueue_frontend', true) !== true) {
			return;
		}

		$this->enqueue_optimizer_script($settings, false);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function enqueue_optimizer_script(array $settings, bool $is_admin): void {
		$css_path = MENAGERIE_PATH . 'assets/css/menagerie-toast.css';
		$dist_js  = MENAGERIE_PATH . 'assets/js/dist/menagerie-optimizer.js';
		$legacy_js = MENAGERIE_PATH . 'assets/js/menagerie-optimizer.js';

		if (! is_readable($css_path)) {
			return;
		}

		$use_dist = is_readable($dist_js);
		$js_path  = $use_dist ? $dist_js : $legacy_js;
		if (! is_readable($js_path)) {
			return;
		}

		$css_ver = (string) filemtime($css_path);
		$js_ver  = (string) filemtime($js_path);

		$js_url = $use_dist
			? MENAGERIE_URL . 'assets/js/dist/menagerie-optimizer.js'
			: MENAGERIE_URL . 'assets/js/menagerie-optimizer.js';

		wp_register_style(
			self::STYLE_HANDLE,
			MENAGERIE_URL . 'assets/css/menagerie-toast.css',
			[],
			$css_ver
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$js_url,
			[],
			$js_ver,
			true
		);

		$config = $this->build_config($settings, $is_admin);

		wp_enqueue_style(self::STYLE_HANDLE);
		wp_enqueue_script(self::SCRIPT_HANDLE);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'MenagerieConfig',
			$config
		);

		wp_set_script_translations(self::SCRIPT_HANDLE, 'menagerie', MENAGERIE_PATH . 'languages');
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function build_config(array $settings, bool $is_admin): array {
		$upload_nonce = wp_create_nonce('menagerie_upload');

		return [
			'context'       => $is_admin ? 'admin' : 'front',
			'enabled'       => ! empty($settings['enabled']),
			'formatMode'    => (string) ($settings['format_mode'] ?? 'auto'),
			'maxWidth'      => (int) ($settings['max_width'] ?? 2560),
			'maxHeight'     => (int) ($settings['max_height'] ?? 2560),
			'quality'       => (int) ($settings['quality'] ?? 85),
			'convertWhenUseful' => ! empty($settings['convert_when_useful']),
			'showToasts'    => ! empty($settings['show_toasts']),
			'preserveTransparency' => ! empty($settings['preserve_transparency']),
			'processAdmin'  => ! empty($settings['process_admin']),
			'processFrontend' => ! empty($settings['process_frontend']),
			'wasmEncoders'  => ! empty($settings['wasm_encoders']) && $this->has_optimizer_dist_bundle(),
			'serverSideOnly' => ! empty($settings['server_side_only']),
			'uploadNonce'   => $upload_nonce,
			'ajaxUrl'       => admin_url('admin-ajax.php'),
			'restUrl'       => esc_url_raw(rest_url()),
			'restNonce'     => wp_create_nonce('wp_rest'),
			'strings'       => [
				'optimizing' => $this->resolve_toast_string($settings, 'toast_optimizing', __('Optimizing your image…', 'menagerie')),
				'optimized'  => $this->resolve_toast_string($settings, 'toast_optimized', __('Image optimized, you may submit.', 'menagerie')),
				'fallback'   => $this->resolve_toast_string($settings, 'toast_fallback', __('Optimization skipped; your original image will upload.', 'menagerie')),
				'dismiss'    => $this->resolve_toast_string($settings, 'toast_dismiss', __('Dismiss notification', 'menagerie')),
			],
		];
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function resolve_toast_string(array $settings, string $key, string $default): string {
		if (! isset($settings[ $key ]) || ! is_string($settings[ $key ])) {
			return $default;
		}
		$custom = trim($settings[ $key ]);

		return $custom !== '' ? $custom : $default;
	}

	/**
	 * Advanced WASM encoders require the Vite-built bundle (not legacy IIFE only).
	 */
	public function has_optimizer_dist_bundle(): bool {
		return is_readable(MENAGERIE_PATH . 'assets/js/dist/menagerie-optimizer.js');
	}

	private function is_settings_page(): bool {
		return isset($_GET['page']) && (string) $_GET['page'] === 'menagerie';
	}

	private function enqueue_admin_settings_style(): void {
		$path = MENAGERIE_PATH . 'assets/css/menagerie-admin-settings.css';
		if (! is_readable($path)) {
			return;
		}
		wp_enqueue_style(
			self::SETTINGS_STYLE_HANDLE,
			MENAGERIE_URL . 'assets/css/menagerie-admin-settings.css',
			[],
			(string) filemtime($path)
		);
	}

	private function should_load_admin_uploader(): bool {
		global $pagenow;

		$allowed = [
			'post.php',
			'post-new.php',
			'upload.php',
			'media.php',
			'async-upload.php',
			'site-editor.php',
			'widgets.php',
			'customize.php',
		];

		if (is_string($pagenow) && in_array($pagenow, $allowed, true)) {
			return true;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && in_array($screen->base, ['post', 'upload', 'media'], true)) {
			return true;
		}

		return false;
	}
}
