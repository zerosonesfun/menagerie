<?php
/**
 * JPEG companion files: public uploads, not Media Library attachments.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class JpegCompanion {
	public const FILE_FIELD = 'menagerie_jpeg_companion';

	/** @var array<int, true> */
	private array $handled_this_request = [];

	public function __construct(
		private Registry $registry,
		private ImageEncoder $encoder
	) {
	}

	public function register(): void {
		add_action('add_attachment', [$this, 'on_add_attachment'], 20, 1);
		add_action('rest_after_insert_attachment', [$this, 'on_rest_after_insert'], 20, 3);
		add_action('delete_attachment', [$this, 'on_delete_attachment'], 10, 1);
		// After metadata/create settles: recover missing companions (failed browser upload, server-only, etc.).
		add_filter('wp_generate_attachment_metadata', [$this, 'filter_maybe_ensure_companion'], 30, 3);
	}

	public function on_add_attachment(int $attachment_id): void {
		if (! current_user_can('upload_files')) {
			return;
		}
		$this->maybe_store_from_upload($attachment_id);
	}

	/**
	 * @param \WP_Post         $attachment Post.
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $creating Whether creating.
	 */
	public function on_rest_after_insert($attachment, $request, $creating): void {
		if ($creating !== true || ! $attachment instanceof \WP_Post) {
			return;
		}
		if (! current_user_can('upload_files')) {
			return;
		}
		$id = (int) $attachment->ID;
		$this->maybe_store_from_upload($id);
		$this->maybe_ensure_missing($id);
	}

	public function on_delete_attachment(int $attachment_id): void {
		$this->delete_for_attachment($attachment_id);
	}

	/**
	 * Persist companion from multipart upload when present.
	 */
	public function maybe_store_from_upload(int $attachment_id): void {
		if ($attachment_id <= 0 || isset($this->handled_this_request[ $attachment_id ])) {
			return;
		}

		$settings = $this->registry->get();
		if (empty($settings['jpeg_companion'])) {
			return;
		}

		if (empty($_FILES[ self::FILE_FIELD ]) || ! is_array($_FILES[ self::FILE_FIELD ])) {
			return;
		}

		$file = $_FILES[ self::FILE_FIELD ];
		if (! isset($file['error'], $file['tmp_name'], $file['name']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
			return;
		}

		$tmp = (string) $file['tmp_name'];
		if ($tmp === '' || ! is_uploaded_file($tmp) || ! is_readable($tmp)) {
			return;
		}

		$size = isset($file['size']) ? (int) $file['size'] : 0;
		$max  = (int) wp_max_upload_size();
		if ($size > 0 && $max > 0 && $size > $max) {
			return;
		}

		$contents = file_get_contents($tmp);
		if ($contents === false || $contents === '') {
			return;
		}
		if ($max > 0 && strlen($contents) > $max) {
			return;
		}

		$name = sanitize_file_name((string) $file['name']);
		if ($this->store_bytes($attachment_id, $contents, $name !== '' ? $name : 'companion.jpg')) {
			// Only suppress the paired REST/classic hook after a successful store.
			$this->handled_this_request[ $attachment_id ] = true;
		}
	}

	/**
	 * @param array<string, mixed> $metadata Metadata.
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $context Context.
	 * @return array<string, mixed>
	 */
	public function filter_maybe_ensure_companion($metadata, $attachment_id, $context = '') {
		if (! is_array($metadata)) {
			return $metadata;
		}
		if ($context === 'update'
			|| LibraryConverter::is_recursing()
			|| ServerFallbackOptimizer::is_recursing()
		) {
			return $metadata;
		}
		$this->maybe_ensure_missing((int) $attachment_id);
		return $metadata;
	}

	/**
	 * Create a companion when the primary is modern but none is stored yet.
	 */
	public function maybe_ensure_missing(int $attachment_id): void {
		if ($attachment_id <= 0) {
			return;
		}
		$settings = $this->registry->get();
		if (empty($settings['jpeg_companion'])) {
			return;
		}
		if (! $this->attachment_is_modern_image($attachment_id)) {
			return;
		}
		if ($this->get_path($attachment_id) !== null) {
			return;
		}
		$this->ensure_from_attachment($attachment_id);
	}

	/**
	 * Store raw JPEG bytes as a companion for an attachment.
	 *
	 * @return bool True on success.
	 */
	public function store_bytes(int $attachment_id, string $contents, string $preferred_name = 'companion.jpg'): bool {
		if ($attachment_id <= 0 || $contents === '') {
			return false;
		}

		if (! $this->bytes_look_like_jpeg($contents)) {
			return false;
		}

		$max = (int) wp_max_upload_size();
		if ($max > 0 && strlen($contents) > $max) {
			return false;
		}

		$tmp = wp_tempnam('menagerie-jpeg-check');
		if (! is_string($tmp) || $tmp === '') {
			return false;
		}
		file_put_contents($tmp, $contents);
		$check = wp_check_filetype_and_ext($tmp, $preferred_name, [ 'jpg|jpeg|jpe' => 'image/jpeg' ]);
		@unlink($tmp);

		$mime = isset($check['type']) ? (string) $check['type'] : '';
		if ($mime !== 'image/jpeg') {
			return false;
		}

		$base = pathinfo($preferred_name, PATHINFO_FILENAME);
		$base = sanitize_file_name(is_string($base) ? $base : 'image');
		if ($base === '') {
			$base = 'image';
		}
		if (! str_ends_with($base, '-menagerie-fb')) {
			$base .= '-menagerie-fb';
		}
		$filename = $base . '.jpg';

		$old_path = $this->get_path($attachment_id);

		$upload = wp_upload_bits($filename, null, $contents);
		if (! empty($upload['error']) || empty($upload['file']) || ! is_string($upload['file'])) {
			return false;
		}

		$path = $upload['file'];
		if (! is_readable($path)) {
			@unlink($path);
			return false;
		}

		$relative = _wp_relative_upload_path($path);
		if (! is_string($relative) || $relative === '' || $this->resolve_under_uploads($relative) === null) {
			@unlink($path);
			return false;
		}

		$width  = 0;
		$height = 0;
		$size   = @getimagesize($path);
		if (is_array($size)) {
			$width  = (int) ($size[0] ?? 0);
			$height = (int) ($size[1] ?? 0);
		}

		update_post_meta($attachment_id, MetaKeys::JPEG_RELATIVE, $relative);
		update_post_meta($attachment_id, MetaKeys::JPEG_WIDTH, $width);
		update_post_meta($attachment_id, MetaKeys::JPEG_HEIGHT, $height);

		if ($old_path !== null && $old_path !== $path && is_file($old_path)) {
			wp_delete_file($old_path);
		}

		return true;
	}

	/**
	 * Generate a JPEG companion from the attachment's current main file (server-side).
	 */
	public function ensure_from_attachment(int $attachment_id): bool {
		$settings = $this->registry->get();
		if (empty($settings['jpeg_companion'])) {
			return false;
		}

		if (! $this->attachment_is_modern_image($attachment_id)) {
			return false;
		}

		$path = get_attached_file($attachment_id, true);
		if (! is_string($path) || $path === '' || ! is_readable($path)) {
			return false;
		}

		$quality = (int) ($settings['jpeg_companion_quality'] ?? 75);
		$bg      = (string) ($settings['jpeg_companion_bg'] ?? '#ffffff');
		$max_w   = max(1, (int) ($settings['max_width'] ?? 2560));
		$max_h   = max(1, (int) ($settings['max_height'] ?? 2560));

		$bytes = $this->encode_jpeg_bytes_with_background($path, $quality, $bg, $max_w, $max_h);
		if ($bytes === null) {
			// Last resort: editor encode without an explicit flatten color.
			$result = $this->encoder->try_encode(
				$path,
				[ 'image/jpeg' ],
				$quality,
				$max_w,
				$max_h
			);
			if ($result === null) {
				return false;
			}
			$bytes = file_get_contents($result['path']);
			if ($result['path'] !== $path && is_file($result['path'])) {
				@unlink($result['path']);
			}
			if ($bytes === false || $bytes === '') {
				return false;
			}
		}

		$base = pathinfo($path, PATHINFO_FILENAME);
		$name = (is_string($base) && $base !== '' ? $base : 'image') . '-menagerie-fb.jpg';

		return $this->store_bytes($attachment_id, $bytes, $name);
	}

	/**
	 * Encode a JPEG with an explicit matte color for transparency.
	 *
	 * @return string|null JPEG bytes or null when unsupported.
	 */
	private function encode_jpeg_bytes_with_background(
		string $path,
		int $quality,
		string $bg,
		int $max_w,
		int $max_h
	): ?string {
		$quality = min(100, max(1, $quality));
		$rgb     = $this->hex_to_rgb($bg);
		if ($rgb === null) {
			$rgb = [ 255, 255, 255 ];
		}

		if (class_exists(\Imagick::class, false)) {
			try {
				$img = new \Imagick($path);
				if (method_exists($img, 'autoOrient')) {
					$img->autoOrient();
				}
				$w = $img->getImageWidth();
				$h = $img->getImageHeight();
				if ($w > 0 && $h > 0) {
					$scale = min($max_w / $w, $max_h / $h, 1.0);
					if ($scale < 1.0) {
						$filter = defined('Imagick::FILTER_LANCZOS')
							? constant('Imagick::FILTER_LANCZOS')
							: (defined('Imagick::FILTER_TRIANGLE') ? constant('Imagick::FILTER_TRIANGLE') : 0);
						$img->resizeImage(
							max(1, (int) round($w * $scale)),
							max(1, (int) round($h * $scale)),
							(int) $filter,
							1,
							true
						);
					}
				}
				$img->setImageBackgroundColor(
					sprintf('rgb(%d,%d,%d)', $rgb[0], $rgb[1], $rgb[2])
				);
				if (method_exists($img, 'mergeImageLayers') && defined('Imagick::LAYERMETHOD_FLATTEN')) {
					$flat = $img->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
					if ($flat instanceof \Imagick) {
						$img->clear();
						$img->destroy();
						$img = $flat;
					}
				} elseif (method_exists($img, 'setImageAlphaChannel') && defined('Imagick::ALPHACHANNEL_REMOVE')) {
					$img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
				}
				$img->setImageFormat('jpeg');
				$img->setImageCompressionQuality($quality);
				$blob = $img->getImageBlob();
				$img->clear();
				$img->destroy();
				if (is_string($blob) && $blob !== '') {
					return $blob;
				}
			} catch (\Throwable $e) {
				// Fall through to GD / editor.
			}
		}

		if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
			return null;
		}
		$data = @file_get_contents($path);
		if ($data === false || $data === '') {
			return null;
		}
		$src = @imagecreatefromstring($data);
		if (! is_gd_image($src)) {
			return null;
		}
		$w = imagesx($src);
		$h = imagesy($src);
		if ($w < 1 || $h < 1) {
			imagedestroy($src);
			return null;
		}
		$scale = min($max_w / $w, $max_h / $h, 1.0);
		$nw    = max(1, (int) round($w * $scale));
		$nh    = max(1, (int) round($h * $scale));
		$dst   = imagecreatetruecolor($nw, $nh);
		if (! is_gd_image($dst)) {
			imagedestroy($src);
			return null;
		}
		$fill = imagecolorallocate($dst, $rgb[0], $rgb[1], $rgb[2]);
		if ($fill !== false) {
			imagefilledrectangle($dst, 0, 0, $nw, $nh, $fill);
		}
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
		imagedestroy($src);

		ob_start();
		$ok = imagejpeg($dst, null, $quality);
		$blob = ob_get_clean();
		imagedestroy($dst);
		if (! $ok || ! is_string($blob) || $blob === '') {
			return null;
		}
		return $blob;
	}

	/**
	 * @return array{0:int,1:int,2:int}|null
	 */
	private function hex_to_rgb(string $hex): ?array {
		$hex = ltrim($hex, '#');
		if (strlen($hex) === 3) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if (strlen($hex) !== 6 || preg_match('/^[0-9A-Fa-f]{6}$/', $hex) !== 1) {
			return null;
		}
		return [
			(int) hexdec(substr($hex, 0, 2)),
			(int) hexdec(substr($hex, 2, 2)),
			(int) hexdec(substr($hex, 4, 2)),
		];
	}

	public function get_path(int $attachment_id): ?string {
		$relative = get_post_meta($attachment_id, MetaKeys::JPEG_RELATIVE, true);
		if (! is_string($relative) || $relative === '') {
			return null;
		}
		$path = $this->resolve_under_uploads($relative);
		if ($path === null || ! is_readable($path)) {
			return null;
		}
		return $path;
	}

	public function get_url(int $attachment_id): ?string {
		$relative = get_post_meta($attachment_id, MetaKeys::JPEG_RELATIVE, true);
		if (! is_string($relative) || $relative === '') {
			return null;
		}
		// Require a readable on-disk file so URLs cannot point at poisoned paths.
		if ($this->get_path($attachment_id) === null) {
			return null;
		}

		$uploads = wp_get_upload_dir();
		if (! empty($uploads['error']) || empty($uploads['baseurl'])) {
			return null;
		}
		$url = trailingslashit((string) $uploads['baseurl']) . ltrim(str_replace('\\', '/', $relative), '/');
		$url = esc_url_raw($url);
		return $url !== '' ? $url : null;
	}

	/**
	 * @return array{url: string, width: int, height: int}|null
	 */
	public function get_info(int $attachment_id): ?array {
		if (! $this->attachment_is_modern_image($attachment_id)) {
			return null;
		}

		$url  = $this->get_url($attachment_id);
		$path = $this->get_path($attachment_id);
		if ($url === null || $path === null) {
			return null;
		}
		$width  = (int) get_post_meta($attachment_id, MetaKeys::JPEG_WIDTH, true);
		$height = (int) get_post_meta($attachment_id, MetaKeys::JPEG_HEIGHT, true);
		if ($width < 1 || $height < 1) {
			$size = @getimagesize($path);
			if (is_array($size)) {
				$width  = (int) ($size[0] ?? 0);
				$height = (int) ($size[1] ?? 0);
			}
		}

		return [
			'url'    => $url,
			'width'  => $width,
			'height' => $height,
		];
	}

	public function delete_for_attachment(int $attachment_id): void {
		$path = $this->get_path($attachment_id);
		if ($path !== null && is_file($path)) {
			wp_delete_file($path);
		}
		delete_post_meta($attachment_id, MetaKeys::JPEG_RELATIVE);
		delete_post_meta($attachment_id, MetaKeys::JPEG_WIDTH);
		delete_post_meta($attachment_id, MetaKeys::JPEG_HEIGHT);
	}

	private function attachment_is_modern_image(int $attachment_id): bool {
		$mime = get_post_mime_type($attachment_id);
		return is_string($mime) && in_array($mime, [ 'image/avif', 'image/webp' ], true);
	}

	/**
	 * Resolve a relative uploads path only if it stays under the uploads basedir.
	 */
	private function resolve_under_uploads(string $relative): ?string {
		$relative = str_replace('\\', '/', $relative);
		$relative = ltrim($relative, '/');
		if ($relative === ''
			|| str_contains($relative, '..')
			|| str_contains($relative, "\0")
			|| preg_match('#^[a-zA-Z]:#', $relative) === 1
		) {
			return null;
		}

		$uploads = wp_get_upload_dir();
		if (! empty($uploads['error']) || empty($uploads['basedir'])) {
			return null;
		}

		$basedir = wp_normalize_path((string) $uploads['basedir']);
		$path    = wp_normalize_path(path_join($basedir, $relative));
		$prefix  = trailingslashit($basedir);
		if ($path !== $basedir && ! str_starts_with($path, $prefix)) {
			return null;
		}

		if (is_file($path)) {
			$real_path = realpath($path);
			$real_base = realpath($basedir);
			if ($real_path === false || $real_base === false) {
				return null;
			}
			$real_path = wp_normalize_path($real_path);
			$real_base = wp_normalize_path($real_base);
			$real_prefix = trailingslashit($real_base);
			if ($real_path !== $real_base && ! str_starts_with($real_path, $real_prefix)) {
				return null;
			}
			return $real_path;
		}

		return $path;
	}

	private function bytes_look_like_jpeg(string $contents): bool {
		// JPEG SOI marker.
		return strlen($contents) >= 3
			&& ord($contents[0]) === 0xFF
			&& ord($contents[1]) === 0xD8
			&& ord($contents[2]) === 0xFF;
	}
}
