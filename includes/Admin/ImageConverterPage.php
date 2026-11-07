<?php
/**
 * Tools → Image Converter admin screen.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Admin;

use Menagerie\Upload\ImageEncoder;

if (! defined('ABSPATH')) {
	exit;
}

final class ImageConverterPage {
	public const SLUG = 'menagerie-image-converter';

	public const CAPABILITY = 'manage_options';

	public function __construct(
		private ImageEncoder $encoder
	) {
	}

	public function register(): void {
		add_management_page(
			__('Image Converter', 'menagerie'),
			__('Image Converter', 'menagerie'),
			self::CAPABILITY,
			self::SLUG,
			[$this, 'render_page']
		);
	}

	public function render_page(): void {
		if (! current_user_can(self::CAPABILITY)) {
			return;
		}

		$targets = [
			'avif' => [
				'label'     => 'AVIF',
				'mime'      => 'image/avif',
				'supported' => $this->encoder->supports_output_mime('image/avif'),
			],
			'webp' => [
				'label'     => 'WebP',
				'mime'      => 'image/webp',
				'supported' => $this->encoder->supports_output_mime('image/webp'),
			],
			'png'  => [
				'label'     => 'PNG',
				'mime'      => 'image/png',
				'supported' => $this->encoder->supports_output_mime('image/png'),
			],
			'jpg'  => [
				'label'     => 'JPG',
				'mime'      => 'image/jpeg',
				'supported' => $this->encoder->supports_output_mime('image/jpeg'),
			],
		];

		echo '<div class="wrap menagerie-converter-wrap">';
		echo '<h1>' . esc_html__('Image Converter', 'menagerie') . '</h1>';
		echo '<p class="description">' . esc_html__(
			'Convert existing Media Library images to one format. Converter quality and “Convert when useful” follow Settings → Menagerie. GIF and SVG files are skipped. JPEG flattens transparency. When JPEG companion is enabled, AVIF/WebP conversions also generate JPEG companions (full and sized when enabled) as public files, not Media Library items. Images already in the target format are skipped.',
			'menagerie'
		) . '</p>';

		$unsupported = array_filter(
			$targets,
			static function (array $t): bool {
				return empty($t['supported']);
			}
		);
		if ($unsupported !== []) {
			$names = array_map(
				static function (array $t): string {
					return (string) $t['label'];
				},
				$unsupported
			);
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: comma-separated format names */
					__('This host cannot encode: %s. Those options are disabled. Check Tools → Site Health → Info → Media handling.', 'menagerie'),
					implode(', ', $names)
				)
			);
			echo '</p></div>';
		}

		echo '<form id="menagerie-image-converter-form" class="menagerie-converter-form">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="menagerie-convert-target">' . esc_html__('Convert to', 'menagerie') . '</label></th><td>';
		$default = 'webp';
		if (empty($targets['webp']['supported'])) {
			foreach ($targets as $key => $t) {
				if (! empty($t['supported'])) {
					$default = $key;
					break;
				}
			}
		}
		$any_supported = false;
		foreach ($targets as $t) {
			if (! empty($t['supported'])) {
				$any_supported = true;
				break;
			}
		}
		printf(
			'<select name="target" id="menagerie-convert-target" required%s>',
			$any_supported ? '' : ' disabled'
		);
		foreach ($targets as $key => $t) {
			printf(
				'<option value="%s"%s%s>%s</option>',
				esc_attr($key),
				selected($key, $default, false),
				empty($t['supported']) ? ' disabled' : '',
				esc_html((string) $t['label'])
			);
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';

		$submit_attrs = $any_supported ? [] : [ 'disabled' => 'disabled' ];
		submit_button(__('Convert library', 'menagerie'), 'primary', 'menagerie-convert-submit', false, $submit_attrs);
		if (! $any_supported) {
			echo '<p class="description">' . esc_html__('No convertible formats are available on this host.', 'menagerie') . '</p>';
		}

		echo '</form>';

		echo '<div id="menagerie-converter-progress" class="menagerie-converter-progress" hidden>';
		echo '<div class="menagerie-converter-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-labelledby="menagerie-converter-status">';
		echo '<div class="menagerie-converter-progress__bar" style="width:0%"></div>';
		echo '</div>';
		echo '<p id="menagerie-converter-status" class="menagerie-converter-status" aria-live="polite"></p>';
		echo '</div>';

		echo '<div id="menagerie-converter-summary" class="menagerie-converter-summary" hidden></div>';
		echo '</div>';
	}
}
