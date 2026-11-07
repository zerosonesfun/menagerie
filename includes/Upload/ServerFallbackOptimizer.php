<?php
/**
 * Last-resort server-side optimization when the client did not report success.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class ServerFallbackOptimizer {
	private static bool $recursing = false;

	public function __construct(
		private Registry $registry,
		private ImageEncoder $encoder,
		private JpegCompanion $companion
	) {
	}

	/**
	 * Whether a metadata pass is nested inside server-side fallback optimization.
	 */
	public static function is_recursing(): bool {
		return self::$recursing;
	}

	public function register(): void {
		/*
		 * Run before WordPress Big Image scaling so we do not compare against a core-downscaled
		 * "-scaled" file and skip optimization, and so subsizes are generated from the same
		 * source Menagerie will encode from (see wp_create_image_subsizes() in core).
		 */
		add_filter('big_image_size_threshold', [$this, 'filter_big_image_threshold'], 5, 4);
		add_filter('wp_generate_attachment_metadata', [$this, 'filter_metadata'], 10, 3);
	}

	/**
	 * When server fallback will handle this attachment, disable core Big Image (-scaled) so
	 * dimensions/bytes reflect the uploaded original until filter_metadata runs.
	 *
	 * @param int|false            $threshold     Default 2560, or false to disable scaling.
	 * @param array{0:int,1:int}   $imagesize     Width/height from the file on disk.
	 * @param string               $file          Absolute path (same reference core uses next).
	 * @param int                  $attachment_id Attachment post ID.
	 * @return int|false
	 */
	public function filter_big_image_threshold($threshold, $imagesize, $file, $attachment_id) {
		if (self::$recursing || LibraryConverter::is_recursing()) {
			return $threshold;
		}
		$id = (int) $attachment_id;
		if ($id <= 0 || ! $this->should_optimize($id)) {
			return $threshold;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @param int                  $attachment_id Attachment post ID.
	 * @param string               $_context      'create' or 'update' (WP 5.3+). Not a filesystem path.
	 * @return array<string, mixed>
	 */
	public function filter_metadata($metadata, $attachment_id, $_context = '') {
		if (self::$recursing || LibraryConverter::is_recursing()) {
			return $metadata;
		}

		if (! is_array($metadata)) {
			return $metadata;
		}

		/*
		 * WP 7.1 client-side media finalize (and big-image deferred sizes) pass context
		 * 'update'. Skip that pass: create already handled optimization, or meta says done.
		 */
		if ($_context === 'update') {
			return $metadata;
		}

		$id = (int) $attachment_id;
		if ($id <= 0) {
			return $metadata;
		}

		if (! $this->should_optimize($id)) {
			return $metadata;
		}

		// Third argument is $context ('create'|'update'), never a path — use attached file only.
		$path = get_attached_file($id, true);
		if (! is_string($path) || $path === '' || ! is_readable($path)) {
			return $metadata;
		}

		$settings = $this->registry->get();
		$original_bytes = @filesize($path);
		if ($original_bytes === false || $original_bytes < 1) {
			return $metadata;
		}

		$mime_in = get_post_mime_type($id);
		if (! is_string($mime_in)) {
			return $metadata;
		}

		$has_alpha = $this->encoder->image_has_alpha_channel($path, $mime_in);
		$types     = $this->pick_output_mimes(
			(string) ($settings['format_mode'] ?? 'auto'),
			$has_alpha,
			! empty($settings['preserve_transparency'])
		);

		$result = $this->encoder->try_encode(
			$path,
			$types,
			(int) ($settings['quality'] ?? 85),
			(int) ($settings['max_width'] ?? 2560),
			(int) ($settings['max_height'] ?? 2560)
		);

		if ($result === null) {
			return $metadata;
		}

		$new_path = $result['path'];
		$new_mime = $result['mime'];
		$new_size = $result['bytes'];

		if (! empty($settings['convert_when_useful']) && $new_size >= $original_bytes) {
			if ($new_path !== $path && is_file($new_path)) {
				@unlink($new_path);
			}
			return $metadata;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		self::$recursing = true;
		try {
			$new_metadata = wp_generate_attachment_metadata($id, $new_path);
		} finally {
			self::$recursing = false;
		}

		if (! is_array($new_metadata) || ! isset($new_metadata['file'])) {
			if (is_array($new_metadata)) {
				$this->encoder->delete_intermediate_files($new_path, $new_metadata);
			}
			if ($new_path !== $path && is_file($new_path)) {
				@unlink($new_path);
			}
			return $metadata;
		}

		$this->encoder->delete_intermediate_files($path, $metadata);

		if ($new_path !== $path && is_file($path)) {
			@unlink($path);
		}

		update_attached_file($id, $new_path);

		wp_update_post(
			[
				'ID'             => $id,
				'post_mime_type' => $new_mime,
			]
		);

		$this->encoder->write_success_meta($id, $original_bytes, $new_size, $new_mime);

		if (in_array($new_mime, [ 'image/avif', 'image/webp' ], true)) {
			// Keep a browser-uploaded companion when present; only fill gaps.
			$this->companion->maybe_ensure_missing($id);
		} else {
			$this->companion->delete_for_attachment($id);
		}

		return $new_metadata;
	}

	private function should_optimize(int $attachment_id): bool {
		$s = $this->registry->get();
		if (empty($s['enabled']) && empty($s['server_side_only'])) {
			return false;
		}
		if (empty($s['server_side_fallback']) && empty($s['server_side_only'])) {
			return false;
		}
		if (empty($s['process_frontend']) && empty($s['process_admin'])) {
			return false;
		}
		if (get_post_meta($attachment_id, MetaKeys::PROCESSED, true) === '1') {
			return false;
		}
		if (! wp_attachment_is_image($attachment_id)) {
			return false;
		}
		$mime = get_post_mime_type($attachment_id);
		if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
			return false;
		}
		if ($mime === 'image/gif' || $mime === 'image/svg+xml') {
			return false;
		}

		return true;
	}

	/**
	 * Mirrors pickOutputTypes in src/app.js.
	 *
	 * @return list<string>
	 */
	private function pick_output_mimes(string $mode, bool $has_alpha, bool $preserve): array {
		if ($mode === 'jpeg') {
			return ['image/jpeg'];
		}
		if ($mode === 'webp') {
			return ['image/webp'];
		}
		// auto
		if ($has_alpha && $preserve) {
			return ['image/avif', 'image/webp', 'image/png'];
		}
		if ($has_alpha && ! $preserve) {
			return ['image/avif', 'image/webp', 'image/jpeg'];
		}

		return ['image/avif', 'image/webp', 'image/jpeg'];
	}
}
