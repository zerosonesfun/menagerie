<?php
/**
 * Settings page under Settings menu.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Admin;

use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class SettingsPage {
	private const SLUG = 'menagerie';

	public function __construct(
		private Registry $registry
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

		echo '<div class="wrap menagerie-settings-wrap">';
		echo '<h1>' . esc_html__('Menagerie', 'menagerie') . '</h1>';
		echo '<p class="description">' . esc_html__(
			'WordPress 7.1+ handles upload encoding and thumbnails. Menagerie adds JPEG companions for older browsers and social previews, plus Tools → Image Converter for bulk library conversion.',
			'menagerie'
		) . '</p>';

		echo '<form method="post" action="options.php">';
		settings_fields(Registry::SETTINGS_GROUP);
		echo '<table class="form-table" role="presentation">';

		$this->select_row(
			'output_format',
			__('Upload output format', 'menagerie'),
			[
				'keep' => __('Keep as uploaded (WordPress default)', 'menagerie'),
				'webp' => __('Convert JPEG/PNG to WebP', 'menagerie'),
				'avif' => __('Convert JPEG/PNG to AVIF', 'menagerie'),
			],
			$settings,
			__('Uses WordPress’s native image_editor_output_format filter. Client-side media processing (Chrome/Edge) and server uploads both honor this. Pair with JPEG companion below for older browsers and social previews.', 'menagerie')
		);

		$this->checkbox_row(
			'jpeg_companion',
			__('JPEG companion', 'menagerie'),
			__('When an attachment is AVIF or WebP, generate a JPEG companion in uploads (not a Media Library item) for older browsers and social previews. Transparent areas are flattened onto the background color below.', 'menagerie'),
			$settings
		);
		$this->number_row(
			'jpeg_companion_quality',
			__('JPEG companion quality (1-100)', 'menagerie'),
			1,
			100,
			$settings,
			__('Default 75. Used only for companion JPEGs.', 'menagerie')
		);
		$this->text_row(
			'jpeg_companion_bg',
			__('JPEG companion background', 'menagerie'),
			$settings,
			__('Hex color used when flattening transparency (default #ffffff).', 'menagerie')
		);
		$this->number_row(
			'jpeg_companion_max',
			__('JPEG companion max long edge (px)', 'menagerie'),
			1,
			8192,
			$settings,
			__('Cap the longest edge of companion JPEGs (default 2560).', 'menagerie')
		);
		$this->checkbox_row(
			'jpeg_companion_sizes',
			__('Sized JPEG companions', 'menagerie'),
			__('Also generate JPEG companions for each intermediate size WordPress created for the attachment (used in picture fallback srcset).', 'menagerie'),
			$settings
		);
		$this->checkbox_row(
			'picture_fallback',
			__('Automatic picture fallback', 'menagerie'),
			__('Wrap standard WordPress images that have a JPEG companion in a <picture> element so supporting browsers get AVIF/WebP and others get JPEG (with a JPEG srcset when sized companions exist).', 'menagerie'),
			$settings
		);
		$this->checkbox_row(
			'og_jpeg_companion',
			__('Open Graph uses JPEG companion', 'menagerie'),
			__('Prefer the full-size JPEG companion for og:image when the SEO image maps to that attachment.', 'menagerie'),
			$settings
		);

		echo '<tr><th scope="row" colspan="2"><h2 class="title" style="margin:1em 0 0;">' . esc_html__('Upload toasts', 'menagerie') . '</h2></th></tr>';
		$this->checkbox_row(
			'show_toasts_admin',
			__('Show toasts in the backend (admin)', 'menagerie'),
			__('Turn on or off status messages after Media Library and block editor uploads.', 'menagerie'),
			$settings
		);
		$this->checkbox_row(
			'show_toasts_frontend',
			__('Show toasts on the frontend', 'menagerie'),
			__('Turn on or off status messages after front-end uploads (for users who can upload files).', 'menagerie'),
			$settings
		);

		$toast_defaults = $this->registry->get_toast_defaults();
		echo '<tr class="menagerie-toast-texts"><th scope="row">' . esc_html__('Toast message text', 'menagerie') . '</th><td>';
		echo '<p class="description">' . esc_html__('Optional. Leave a field blank to use the default (translated when a language pack is available). Max 500 characters each.', 'menagerie') . '</p>';
		echo '<table class="widefat striped" style="margin-top:0.5em;max-width:48rem;"><tbody>';
		$this->toast_text_row(
			'toast_converted_avif',
			__('Converted to AVIF', 'menagerie'),
			$settings,
			$toast_defaults['toast_converted_avif']
		);
		$this->toast_text_row(
			'toast_fallback_webp',
			__('AVIF failed, used WebP', 'menagerie'),
			$settings,
			$toast_defaults['toast_fallback_webp']
		);
		$this->toast_text_row(
			'toast_converted_webp',
			__('Converted to WebP', 'menagerie'),
			$settings,
			$toast_defaults['toast_converted_webp']
		);
		$this->toast_text_row(
			'toast_failed_keep',
			__('Conversion failed', 'menagerie'),
			$settings,
			$toast_defaults['toast_failed_keep']
		);
		$this->toast_text_row(
			'toast_companion_ok',
			__('JPEG companion created', 'menagerie'),
			$settings,
			$toast_defaults['toast_companion_ok']
		);
		$this->toast_text_row(
			'toast_dismiss',
			__('Dismiss button (accessibility)', 'menagerie'),
			$settings,
			$toast_defaults['toast_dismiss']
		);
		echo '</tbody></table></td></tr>';

		echo '<tr><th scope="row" colspan="2"><h2 class="title" style="margin:1em 0 0;">' . esc_html__('Image Converter', 'menagerie') . '</h2></th></tr>';
		$this->number_row(
			'converter_quality',
			__('Converter quality (1-100)', 'menagerie'),
			1,
			100,
			$settings,
			__('Used by Tools → Image Converter when encoding to AVIF, WebP, JPEG, or PNG.', 'menagerie')
		);
		$this->checkbox_row(
			'convert_when_useful',
			__('Convert when useful', 'menagerie'),
			__('Skip re-encoding in the Image Converter when the new file would not be smaller.', 'menagerie'),
			$settings
		);

		echo '</table>';
		submit_button(__('Save Changes', 'menagerie'));
		echo '</form></div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function checkbox_row(string $key, string $label, string $description, array $settings): void {
		$name    = Registry::OPTION_NAME . '[' . $key . ']';
		$id      = 'menagerie-' . $key;
		$desc_id = $id . '-desc';
		$checked = ! empty($settings[ $key ]);
		echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
		printf(
			'<input type="hidden" name="%1$s" value="0" />' .
			'<input type="checkbox" name="%1$s" id="%2$s" value="1" %3$s aria-describedby="%4$s" />' .
			'<p class="description" id="%4$s">%5$s</p>',
			esc_attr($name),
			esc_attr($id),
			checked($checked, true, false),
			esc_attr($desc_id),
			esc_html($description)
		);
		echo '</td></tr>';
	}

	/**
	 * @param array<string, string> $options
	 * @param array<string, mixed>  $settings
	 */
	private function select_row(string $key, string $label, array $options, array $settings, ?string $help = null): void {
		$name = Registry::OPTION_NAME . '[' . $key . ']';
		$val  = isset($settings[ $key ]) ? (string) $settings[ $key ] : 'keep';
		$id   = 'menagerie-' . $key;
		$desc_id = $id . '-desc';
		echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
		echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '"';
		if ($help !== null && $help !== '') {
			echo ' aria-describedby="' . esc_attr($desc_id) . '"';
		}
		echo '>';
		foreach ($options as $value => $text) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr($value),
				selected($val, $value, false),
				esc_html($text)
			);
		}
		echo '</select>';
		if ($help !== null && $help !== '') {
			echo '<p class="description" id="' . esc_attr($desc_id) . '">' . esc_html($help) . '</p>';
		}
		echo '</td></tr>';
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
	private function text_row(string $key, string $label, array $settings, ?string $help = null): void {
		$name = Registry::OPTION_NAME . '[' . $key . ']';
		$val  = isset($settings[ $key ]) ? (string) $settings[ $key ] : '';
		echo '<tr><th scope="row"><label for="menagerie-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" name="%1$s" id="menagerie-%2$s" value="%3$s" pattern="#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?" maxlength="7" />',
			esc_attr($name),
			esc_attr($key),
			esc_attr($val)
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
