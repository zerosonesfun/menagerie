<?php
/**
 * Settings page under Settings menu.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Admin;

use Menagerie\Assets\AssetLoader;
use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class SettingsPage {
	private const SLUG = 'menagerie';

	public function __construct(
		private Registry $registry,
		private ConflictDetector $conflicts,
		private AssetLoader $assets
	) {
	}

	public function register(): void {
		add_options_page(
			__('Menagerie', 'menagerie'),
			__('Menagerie', 'menagerie'),
			'manage_options',
			self::SLUG,
			[$this, 'render_page']
		);
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$settings = $this->registry->get();
		$conflict_plugins = [];
		if (! empty($settings['detect_conflicts'])) {
			$conflict_plugins = $this->conflicts->get_conflicting_plugins();
		}

		echo '<div class="wrap menagerie-settings-wrap">';
		echo '<h1>' . esc_html__('Menagerie', 'menagerie') . '</h1>';
		echo '<p class="description">' . esc_html__('Optimize images in the visitor’s browser before upload. If optimization cannot run, the original file is uploaded unchanged.', 'menagerie') . '</p>';

		if ($conflict_plugins !== []) {
			echo '<div class="notice notice-info inline menagerie-conflict-status"><p><strong>' . esc_html__('Other optimization plugins detected', 'menagerie') . '</strong></p><ul style="list-style:disc;padding-left:1.5em;">';
			foreach ($conflict_plugins as $p) {
				echo '<li>' . esc_html($p['name']) . '</li>';
			}
			echo '</ul><p>' . esc_html__('Using multiple optimizers may compress images twice. Adjust settings here or disable overlapping features elsewhere.', 'menagerie') . '</p></div>';
		}

		if (! empty($settings['wasm_encoders']) && ! $this->assets->has_optimizer_dist_bundle()) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__('Advanced encoders are enabled, but the built JavaScript bundle (assets/js/dist/) is missing. Run npm install and npm run build in the plugin folder, or install a release that includes dist/. Until then, the browser’s built-in encoder is used.', 'menagerie') . '</p></div>';
		}

		echo '<form method="post" action="options.php">';
		settings_fields(Registry::SETTINGS_GROUP);
		echo '<table class="form-table" role="presentation">';

		$this->checkbox_row('enabled', __('Enable client-side optimization', 'menagerie'), __('When disabled, Menagerie does not process images.', 'menagerie'), $settings);
		$this->select_row(
			'format_mode',
			__('Output format', 'menagerie'),
			[
				'auto' => __('Auto (AVIF or WebP when supported, else JPEG)', 'menagerie'),
				'webp' => __('WebP (fallback to JPEG)', 'menagerie'),
				'jpeg' => __('JPEG', 'menagerie'),
			],
			$settings
		);
		$this->number_row('max_width', __('Max width (px)', 'menagerie'), 1, 8192, $settings);
		$this->number_row('max_height', __('Max height (px)', 'menagerie'), 1, 8192, $settings);
		$this->number_row(
			'quality',
			__('Quality (1–100)', 'menagerie'),
			1,
			100,
			$settings,
			__('Higher values preserve more detail (and larger files). Typical web range is roughly 80–90. Downscaling uses high-quality resampling in the browser.', 'menagerie')
		);
		$this->checkbox_row(
			'wasm_encoders',
			__('Advanced encoders (WebAssembly)', 'menagerie'),
			__('Use MozJPEG, WebP, and AVIF codecs in the browser for typically better compression than the built-in canvas encoder. Loads extra data on first use; may be slower on low-end devices. Falls back automatically if unavailable.', 'menagerie'),
			$settings
		);
		$this->checkbox_row('convert_when_useful', __('Convert when useful', 'menagerie'), __('Skip re-encoding when the image is already small enough.', 'menagerie'), $settings);
		$this->checkbox_row('preserve_transparency', __('Preserve transparency when possible', 'menagerie'), __('When off, transparent images may be flattened for JPEG.', 'menagerie'), $settings);
		$this->checkbox_row('show_toasts', __('Show toast notifications', 'menagerie'), __('Brief status messages during optimization.', 'menagerie'), $settings);

		echo '<tr class="menagerie-toast-texts"><th scope="row">' . esc_html__('Toast message text', 'menagerie') . '</th><td>';
		echo '<p class="description">' . esc_html__('Optional. Leave a field blank to use the default (translated when a language pack is available). Max 500 characters each.', 'menagerie') . '</p>';
		echo '<table class="widefat striped" style="margin-top:0.5em;max-width:48rem;"><tbody>';
		$this->toast_text_row(
			'toast_optimizing',
			__('While optimizing', 'menagerie'),
			$settings,
			__('Optimizing your image…', 'menagerie')
		);
		$this->toast_text_row(
			'toast_optimized',
			__('After successful optimization', 'menagerie'),
			$settings,
			__('Image optimized, you may submit.', 'menagerie')
		);
		$this->toast_text_row(
			'toast_fallback',
			__('When optimization is skipped', 'menagerie'),
			$settings,
			__('Optimization skipped; your original image will upload.', 'menagerie')
		);
		$this->toast_text_row(
			'toast_dismiss',
			__('Dismiss button (accessibility)', 'menagerie'),
			$settings,
			__('Dismiss notification', 'menagerie')
		);
		echo '</tbody></table></td></tr>';

		$this->checkbox_row('detect_conflicts', __('Detect other optimization plugins', 'menagerie'), __('Show notices when similar plugins are active.', 'menagerie'), $settings);
		$this->checkbox_row('process_admin', __('Process uploads in the admin', 'menagerie'), __('Media Library, block editor, and admin file inputs.', 'menagerie'), $settings);
		$this->checkbox_row('process_frontend', __('Process uploads on the front end', 'menagerie'), __('Public forms and file inputs (when safe to intercept).', 'menagerie'), $settings);

		echo '</table>';
		submit_button(__('Save Changes', 'menagerie'));
		echo '</form></div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function checkbox_row(string $key, string $label, string $description, array $settings): void {
		$name  = Registry::OPTION_NAME . '[' . $key . ']';
		$checked = ! empty($settings[ $key ]);
		echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr($name),
			checked($checked, true, false),
			esc_html($description)
		);
		echo '</td></tr>';
	}

	/**
	 * @param array<string, string> $options
	 * @param array<string, mixed> $settings
	 */
	private function select_row(string $key, string $label, array $options, array $settings): void {
		$name = Registry::OPTION_NAME . '[' . $key . ']';
		$val  = isset($settings[ $key ]) ? (string) $settings[ $key ] : 'auto';
		echo '<tr><th scope="row"><label for="menagerie-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
		echo '<select name="' . esc_attr($name) . '" id="menagerie-' . esc_attr($key) . '">';
		foreach ($options as $value => $text) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr($value),
				selected($val, $value, false),
				esc_html($text)
			);
		}
		echo '</select></td></tr>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function number_row(string $key, string $label, int $min, int $max, array $settings, ?string $help = null): void {
		$name = Registry::OPTION_NAME . '[' . $key . ']';
		$val  = isset($settings[ $key ]) ? (int) $settings[ $key ] : 0;
		echo '<tr><th scope="row"><label for="menagerie-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
		printf(
			'<input type="number" class="small-text" name="%s" id="menagerie-%s" min="%d" max="%d" value="%d" />',
			esc_attr($name),
			esc_attr($key),
			$min,
			$max,
			$val
		);
		if ($help !== null && $help !== '') {
			echo '<p class="description">' . esc_html($help) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function toast_text_row(string $key, string $label, array $settings, string $default_example): void {
		$name = Registry::OPTION_NAME . '[' . $key . ']';
		$val  = isset($settings[ $key ]) ? (string) $settings[ $key ] : '';
		echo '<tr><th scope="row"><label for="menagerie-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
		printf(
			'<textarea class="large-text" rows="2" name="%1$s" id="menagerie-%2$s" maxlength="500" placeholder="%3$s">%4$s</textarea>',
			esc_attr($name),
			esc_attr($key),
			esc_attr($default_example),
			esc_textarea($val)
		);
		echo '</td></tr>';
	}
}
