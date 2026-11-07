<?php
/**
 * Convert an existing media library attachment to a target MIME type.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class LibraryConverter {
	private static bool $recursing = false;

	public function __construct(
		private Registry $registry,
		private ImageEncoder $encoder,
		private JpegCompanion $companion
	) {
	}

	/**
	 * Whether a metadata generation pass is nested inside a library conversion.
	 */
	public static function is_recursing(): bool {
		return self::$recursing;
	}

	/**
	 * @return array{
	 *   status: 'converted'|'skipped'|'failed',
	 *   message?: string,
	 *   original_bytes?: int,
	 *   new_bytes?: int
	 * }
	 */
	public function convert_attachment(int $attachment_id, string $target_mime): array {
		if ($attachment_id <= 0) {
			return [
				'status'  => 'failed',
				'message' => __('Invalid attachment.', 'menagerie'),
			];
		}

		if (! current_user_can('edit_post', $attachment_id)) {
			return [
				'status'  => 'failed',
				'message' => __('You cannot edit this attachment.', 'menagerie'),
			];
		}

		if (! wp_attachment_is_image($attachment_id)) {
			return [
				'status'  => 'skipped',
				'message' => __('Not an image.', 'menagerie'),
			];
		}

		$mime_in = get_post_mime_type($attachment_id);
		if (! is_string($mime_in) || ! str_starts_with($mime_in, 'image/')) {
			return [
				'status'  => 'skipped',
				'message' => __('Not an image.', 'menagerie'),
			];
		}

		if ($mime_in === 'image/gif' || $mime_in === 'image/svg+xml') {
			return [
				'status'  => 'skipped',
				'message' => __('GIF and SVG files are left unchanged.', 'menagerie'),
			];
		}

		$path = get_attached_file($attachment_id, true);
		if (! is_string($path) || $path === '' || ! is_readable($path)) {
			return [
				'status'  => 'failed',
				'message' => __('File is missing or unreadable.', 'menagerie'),
			];
		}

		$original_bytes = @filesize($path);
		if ($original_bytes === false || $original_bytes < 1) {
			return [
				'status'  => 'failed',
				'message' => __('Could not read file size.', 'menagerie'),
			];
		}

		$settings = $this->registry->get();
		$result   = $this->encoder->try_encode(
			$path,
			[ $target_mime ],
			(int) ($settings['quality'] ?? 85),
			(int) ($settings['max_width'] ?? 2560),
			(int) ($settings['max_height'] ?? 2560)
		);

		if ($result === null) {
			return [
				'status'  => 'failed',
				'message' => __('Encoding failed (format may be unsupported on this host).', 'menagerie'),
			];
		}

		$new_path = $result['path'];
		$new_mime = $result['mime'];
		$new_size = $result['bytes'];

		if (! empty($settings['convert_when_useful']) && $new_size >= $original_bytes) {
			if ($new_path !== $path && is_file($new_path)) {
				@unlink($new_path);
			}

			// Fill a missing companion when the library file is already modern.
			if (in_array($mime_in, [ 'image/avif', 'image/webp' ], true)) {
				$this->companion->maybe_ensure_missing($attachment_id);
			}

			return [
				'status'         => 'skipped',
				'message'        => __('Already small enough; left unchanged.', 'menagerie'),
				'original_bytes' => $original_bytes,
				'new_bytes'      => $new_size,
			];
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$old_metadata = wp_get_attachment_metadata($attachment_id);
		if (! is_array($old_metadata)) {
			$old_metadata = [];
		}

		self::$recursing = true;
		try {
			$new_metadata = wp_generate_attachment_metadata($attachment_id, $new_path);
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

			return [
				'status'  => 'failed',
				'message' => __('Could not regenerate image sizes.', 'menagerie'),
			];
		}

		$this->encoder->delete_intermediate_files($path, $old_metadata);

		if ($new_path !== $path && is_file($path)) {
			@unlink($path);
		}

		update_attached_file($attachment_id, $new_path);
		wp_update_attachment_metadata($attachment_id, $new_metadata);

		wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $new_mime,
			]
		);

		$this->encoder->write_success_meta($attachment_id, $original_bytes, $new_size, $new_mime, false);

		if (in_array($new_mime, [ 'image/avif', 'image/webp' ], true)) {
			$this->companion->ensure_from_attachment($attachment_id);
		} else {
			// Primary is no longer modern — drop any leftover companion file/meta.
			$this->companion->delete_for_attachment($attachment_id);
		}

		return [
			'status'         => 'converted',
			'original_bytes' => $original_bytes,
			'new_bytes'      => $new_size,
		];
	}
}
