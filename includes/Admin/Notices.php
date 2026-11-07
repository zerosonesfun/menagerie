<?php
/**
 * Admin notices: companion / converter / output-format capability warnings.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Admin;

use Menagerie\Settings\Registry;
use Menagerie\Upload\ImageEncoder;

if (! defined('ABSPATH')) {
	exit;
}

final class Notices {
	public function __construct(
		private Registry $registry,
		private ImageEncoder $encoder
	) {
	}

	public function register(): void {
		add_action('admin_notices', [$this, 'render_capability_notice']);
	}

	/**
	 * Warn when the host cannot encode JPEG companions, decode modern sources,
	 * or encode the selected upload output format on non-CSMP browsers.
	 */
	public function render_capability_notice(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen || ! in_array($screen->id, [ 'settings_page_menagerie', 'tools_page_menagerie-image-converter' ], true)) {
			return;
		}

		$settings = $this->registry->get();
		$problems = [];

		$mode = (string) ($settings['output_format'] ?? 'keep');
		if ($mode === 'webp' || $mode === 'avif') {
			$mime  = $mode === 'avif' ? 'image/avif' : 'image/webp';
			$label = $mode === 'avif' ? 'AVIF' : 'WebP';
			if (! $this->encoder->supports_output_mime($mime)) {
				$problems[] = sprintf(
					/* translators: %s: format name (AVIF or WebP) */
					__('Upload output is set to %s, but this server cannot encode that format. Chrome/Edge with client-side media processing can still convert in the browser; other browsers fall back to the server and may leave JPEG/PNG unchanged. Check Tools → Site Health → Info → Media handling.', 'menagerie'),
					$label
				);
			}
		}

		if (! empty($settings['jpeg_companion']) && ! $this->encoder->supports_output_mime('image/jpeg')) {
			$problems[] = __(
				'This server cannot encode JPEG. JPEG companions and converter JPEG output will fail until GD or ImageMagick supports JPEG.',
				'menagerie'
			);
		}

		$cannot_decode = [];
		if (! $this->encoder->can_decode_mime('image/avif')) {
			$cannot_decode[] = 'AVIF';
		}
		if (! $this->encoder->can_decode_mime('image/webp')) {
			$cannot_decode[] = 'WebP';
		}
		if ($cannot_decode !== [] && ! empty($settings['jpeg_companion'])) {
			$problems[] = sprintf(
				/* translators: %s: comma-separated format names */
				__('This server may not decode %s. JPEG companions for those uploads may fail. Check Tools → Site Health → Info → Media handling.', 'menagerie'),
				implode(', ', $cannot_decode)
			);
		}

		if ($problems === []) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(implode(' ', $problems));
		echo '</p></div>';
	}
}
