<?php
/**
 * Enqueues admin assets (settings + Image Converter) and upload toasts.
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
	private const SETTINGS_STYLE_HANDLE = 'menagerie-admin-settings';

	private const CONVERTER_SCRIPT_HANDLE = 'menagerie-image-converter';

	private const CONVERTER_STYLE_HANDLE = 'menagerie-image-converter';

	private const TOAST_SCRIPT_HANDLE = 'menagerie-toast';

	private const TOAST_STYLE_HANDLE = 'menagerie-toast';

	public function __construct(
		private Registry $registry
	) {
	}

	/**
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin(string $hook_suffix = ''): void {
		if ($this->is_settings_page() && current_user_can('manage_options')) {
			$this->enqueue_admin_settings_style();
		}

		if ($this->is_converter_page() && current_user_can('manage_options')) {
			$this->enqueue_converter_assets();
		}

		$settings = $this->registry->get();
		if (! empty($settings['show_toasts_admin'])
			&& current_user_can('upload_files')
			&& $this->should_enqueue_admin_toasts($hook_suffix)
		) {
			$this->enqueue_toast_assets();
		}
	}

	/**
	 * Limit fetch/XHR toast hooks to screens that actually upload media.
	 */
	private function should_enqueue_admin_toasts(string $hook_suffix): bool {
		if ($hook_suffix === '') {
			return true;
		}
		$hooks = [
			'upload.php',
			'media-new.php',
			'post.php',
			'post-new.php',
			'site-editor.php',
		];
		if (in_array($hook_suffix, $hooks, true)) {
			return true;
		}
		// Block editor / custom post types use post.php / post-new.php; also allow media popups.
		if (str_contains($hook_suffix, 'upload') || str_contains($hook_suffix, 'media')) {
			return true;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && ! empty($screen->is_block_editor)) {
			return true;
		}
		return false;
	}

	public function enqueue_frontend(): void {
		$settings = $this->registry->get();
		if (empty($settings['show_toasts_frontend']) || ! current_user_can('upload_files')) {
			return;
		}
		$this->enqueue_toast_assets();
	}

	private function is_settings_page(): bool {
		return isset($_GET['page']) && (string) $_GET['page'] === 'menagerie';
	}

	private function is_converter_page(): bool {
		return isset($_GET['page']) && (string) $_GET['page'] === 'menagerie-image-converter';
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

	private function enqueue_converter_assets(): void {
		$css_path = MENAGERIE_PATH . 'assets/css/menagerie-image-converter.css';
		$js_path  = MENAGERIE_PATH . 'assets/js/menagerie-image-converter.js';
		if (! is_readable($css_path) || ! is_readable($js_path)) {
			return;
		}

		wp_enqueue_style(
			self::CONVERTER_STYLE_HANDLE,
			MENAGERIE_URL . 'assets/css/menagerie-image-converter.css',
			[],
			(string) filemtime($css_path)
		);

		wp_enqueue_script(
			self::CONVERTER_SCRIPT_HANDLE,
			MENAGERIE_URL . 'assets/js/menagerie-image-converter.js',
			[],
			(string) filemtime($js_path),
			true
		);

		wp_localize_script(
			self::CONVERTER_SCRIPT_HANDLE,
			'MenagerieConverter',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce(\Menagerie\Admin\ImageConverterAjax::NONCE_ACTION),
				'strings' => [
					'starting'         => __('Starting…', 'menagerie'),
					'running'          => __('Converting…', 'menagerie'),
					/* translators: 1: current count, 2: total count */
					'progress'         => __('Converting %1$s of %2$s…', 'menagerie'),
					'done'             => __('Conversion finished.', 'menagerie'),
					'confirm'          => __('Convert all Media Library images to the selected format? This cannot be undone.', 'menagerie'),
					'empty'            => __('No images found in the Media Library.', 'menagerie'),
					/* translators: %d: number of converted images */
					'summaryConverted' => __('Converted: %d', 'menagerie'),
					/* translators: %d: number of skipped images */
					'summarySkipped'   => __('Skipped: %d', 'menagerie'),
					/* translators: %d: number of failed images */
					'summaryFailed'    => __('Failed: %d', 'menagerie'),
					'failuresHeading'  => __('Failed conversions', 'menagerie'),
					/* translators: %d: number of additional failures not listed */
					'andMore'          => __('…and %d more.', 'menagerie'),
					'converted'        => __('Converted', 'menagerie'),
					'skipped'          => __('Skipped', 'menagerie'),
					'failed'           => __('Failed', 'menagerie'),
					'error'            => __('Something went wrong. Please try again.', 'menagerie'),
				],
			]
		);
	}

	private function enqueue_toast_assets(): void {
		$css_path = MENAGERIE_PATH . 'assets/css/menagerie-toast.css';
		$js_path  = MENAGERIE_PATH . 'assets/js/menagerie-toast.js';
		if (! is_readable($css_path) || ! is_readable($js_path)) {
			return;
		}

		wp_enqueue_style(
			self::TOAST_STYLE_HANDLE,
			MENAGERIE_URL . 'assets/css/menagerie-toast.css',
			[],
			(string) filemtime($css_path)
		);

		wp_enqueue_script(
			self::TOAST_SCRIPT_HANDLE,
			MENAGERIE_URL . 'assets/js/menagerie-toast.js',
			[],
			(string) filemtime($js_path),
			true
		);

		$toasts = $this->registry->get_toast_strings();
		wp_localize_script(
			self::TOAST_SCRIPT_HANDLE,
			'MenagerieToast',
			[
				'strings' => [
					'converted_avif' => $toasts['toast_converted_avif'],
					'fallback_webp'  => $toasts['toast_fallback_webp'],
					'converted_webp' => $toasts['toast_converted_webp'],
					'failed_keep'    => $toasts['toast_failed_keep'],
					'companion_ok'   => $toasts['toast_companion_ok'],
					'dismiss'        => $toasts['toast_dismiss'],
				],
			]
		);
	}
}
