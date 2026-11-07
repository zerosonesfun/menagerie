<?php
/**
 * JPEG companion files: public uploads, not Media Library attachments.
 *
 * Generated server-side after WordPress finishes AVIF/WebP uploads (WP 7.1+ CSMP).
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
	public function __construct(
		private Registry $registry,
		private ImageEncoder $encoder
	) {
	}

	public function register(): void {
		add_action('delete_attachment', [$this, 'on_delete_attachment'], 10, 1);
		// WP 7.1 fires create then update after finalize; ensure on both (idempotent).
		add_filter('wp_generate_attachment_metadata', [$this, 'filter_maybe_ensure_companion'], 30, 3);
		// Backfill for REST / classic paths that may miss a metadata pass.
		add_action('add_attachment', [$this, 'on_add_attachment'], 30, 1);
		add_action('rest_after_insert_attachment', [$this, 'on_rest_after_insert'], 30, 3);
	}

	public function on_delete_attachment(int $attachment_id): void {
		$this->delete_for_attachment($attachment_id);
	}

	public function on_add_attachment(int $attachment_id): void {
		if (LibraryConverter::is_recursing() || UploadOutcome::is_recursing()) {
			return;
		}
		$this->maybe_ensure_missing($attachment_id, null);
	}

	/**
	 * @param \WP_Post         $attachment Post.
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $creating Whether creating.
	 */
	public function on_rest_after_insert($attachment, $request, $creating): void {
		unset($request);
		if ($creating !== true || ! $attachment instanceof \WP_Post) {
			return;
		}
		if (LibraryConverter::is_recursing() || UploadOutcome::is_recursing()) {
			return;
		}
		$this->maybe_ensure_missing((int) $attachment->ID, null);
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
		unset($context);
		if (LibraryConverter::is_recursing() || UploadOutcome::is_recursing()) {
			return $metadata;
		}
		$this->maybe_ensure_missing((int) $attachment_id, $metadata);
		return $metadata;
	}

	/**
	 * Create companions when the primary is modern but companions are missing.
	 *
	 * @param array<string, mixed>|null $metadata Live metadata from wp_generate_attachment_metadata when available.
	 */
	public function maybe_ensure_missing(int $attachment_id, ?array $metadata = null): void {
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

		if ($this->get_path($attachment_id) === null) {
			$this->ensure_from_attachment($attachment_id, $metadata);
			return;
		}

		if (! empty($settings['jpeg_companion_sizes'])) {
			$this->ensure_sized_companions($attachment_id, false, $metadata);
		}
	}

	/**
	 * Generate full (and optionally sized) JPEG companions from the attachment file.
	 *
	 * @param array<string, mixed>|null $metadata Live metadata when available.
	 */
	public function ensure_from_attachment(int $attachment_id, ?array $metadata = null): bool {
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
		$max     = max(1, (int) ($settings['jpeg_companion_max'] ?? 2560));

		$bytes = $this->encode_jpeg_bytes_with_background($path, $quality, $bg, $max, $max);
		if ($bytes === null) {
			$result = $this->encoder->try_encode(
				$path,
				[ 'image/jpeg' ],
				$quality,
				$max,
				$max
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

		if (! $this->store_bytes($attachment_id, $bytes, $name)) {
			return false;
		}

		if (! empty($settings['jpeg_companion_sizes'])) {
			$this->ensure_sized_companions($attachment_id, true, $metadata);
		} else {
			$this->delete_sized_companions($attachment_id);
		}

		return true;
	}

	/**
	 * Build JPEG companions for registered intermediate sizes that exist on disk.
	 *
	 * @param bool                      $force When true, regenerate even if a sized file already exists.
	 * @param array<string, mixed>|null $metadata Live metadata from the generate filter when available.
	 */
	public function ensure_sized_companions(int $attachment_id, bool $force = false, ?array $metadata = null): void {
		$settings = $this->registry->get();
		if (empty($settings['jpeg_companion']) || empty($settings['jpeg_companion_sizes'])) {
			return;
		}
		if (! $this->attachment_is_modern_image($attachment_id)) {
			return;
		}

		if (! is_array($metadata) || empty($metadata['sizes']) || ! is_array($metadata['sizes'])) {
			$stored = wp_get_attachment_metadata($attachment_id);
			$metadata = is_array($stored) ? $stored : null;
		}
		if (! is_array($metadata) || empty($metadata['sizes']) || ! is_array($metadata['sizes'])) {
			return;
		}

		$main = get_attached_file($attachment_id, true);
		if (! is_string($main) || $main === '') {
			return;
		}
		$dir = dirname($main);

		$quality = (int) ($settings['jpeg_companion_quality'] ?? 75);
		$bg      = (string) ($settings['jpeg_companion_bg'] ?? '#ffffff');
		$max     = max(1, (int) ($settings['jpeg_companion_max'] ?? 2560));

		$existing = $this->get_sizes_map($attachment_id);
		$map      = $existing;

		foreach ($metadata['sizes'] as $size_name => $info) {
			if (! is_string($size_name) || $size_name === '' || ! is_array($info) || empty($info['file'])) {
				continue;
			}
			$size_name = sanitize_key($size_name);
			if ($size_name === '') {
				continue;
			}

			if (! $force && isset($map[ $size_name ])) {
				$resolved = $this->resolve_under_uploads((string) $map[ $size_name ]);
				if ($resolved !== null && is_readable($resolved)) {
					continue;
				}
			}

			$src = path_join($dir, (string) $info['file']);
			if (! is_readable($src)) {
				continue;
			}

			$w = isset($info['width']) ? (int) $info['width'] : 0;
			$h = isset($info['height']) ? (int) $info['height'] : 0;
			$max_w = $w > 0 ? min($max, $w) : $max;
			$max_h = $h > 0 ? min($max, $h) : $max;

			$bytes = $this->encode_jpeg_bytes_with_background($src, $quality, $bg, $max_w, $max_h);
			if ($bytes === null) {
				$result = $this->encoder->try_encode($src, [ 'image/jpeg' ], $quality, $max_w, $max_h);
				if ($result === null) {
					continue;
				}
				$bytes = file_get_contents($result['path']);
				if ($result['path'] !== $src && is_file($result['path'])) {
					@unlink($result['path']);
				}
				if ($bytes === false || $bytes === '') {
					continue;
				}
			}

			$stem = pathinfo($main, PATHINFO_FILENAME);
			$stem = is_string($stem) && $stem !== '' ? $stem : 'image';
			$name = sanitize_file_name($stem . '-menagerie-fb-' . $size_name . '.jpg');

			$relative = $this->write_companion_file($attachment_id, $bytes, $name, $map[ $size_name ] ?? null);
			if ($relative !== null) {
				$map[ $size_name ] = $relative;
			}
		}

		// Drop map entries for sizes that no longer exist in metadata.
		$valid_names = array_map('sanitize_key', array_keys($metadata['sizes']));
		foreach (array_keys($map) as $key) {
			if (! in_array($key, $valid_names, true)) {
				$old = $this->resolve_under_uploads((string) $map[ $key ]);
				if ($old !== null && is_file($old)) {
					wp_delete_file($old);
				}
				unset($map[ $key ]);
			}
		}

		$this->persist_sizes_map($attachment_id, $map);
	}

	/**
	 * Store raw JPEG bytes as the full-size companion for an attachment.
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

		// Server-generated companions are not user uploads; do not apply wp_max_upload_size().
		// Cap at 64MB to avoid runaway memory writes on pathological inputs.
		if (strlen($contents) > 64 * 1024 * 1024) {
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

		$old_relative = get_post_meta($attachment_id, MetaKeys::JPEG_RELATIVE, true);
		$old_path     = $this->get_path($attachment_id);

		$relative = $this->write_companion_file(
			$attachment_id,
			$contents,
			$filename,
			is_string($old_relative) ? $old_relative : null
		);
		if ($relative === null) {
			return false;
		}

		$path = $this->resolve_under_uploads($relative);
		if ($path === null) {
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
	 * Write JPEG bytes into uploads; returns relative path or null.
	 *
	 * @param string|null $replace_relative Old relative path to replace (same basename preferred).
	 */
	private function write_companion_file(
		int $attachment_id,
		string $contents,
		string $filename,
		?string $replace_relative
	): ?string {
		unset($attachment_id);
		$upload = wp_upload_bits($filename, null, $contents);
		if (! empty($upload['error']) || empty($upload['file']) || ! is_string($upload['file'])) {
			return null;
		}

		$path = $upload['file'];
		if (! is_readable($path)) {
			@unlink($path);
			return null;
		}

		$relative = _wp_relative_upload_path($path);
		if (! is_string($relative) || $relative === '' || $this->resolve_under_uploads($relative) === null) {
			@unlink($path);
			return null;
		}

		if ($replace_relative !== null && $replace_relative !== $relative) {
			$old = $this->resolve_under_uploads($replace_relative);
			if ($old !== null && $old !== $path && is_file($old)) {
				wp_delete_file($old);
			}
		}

		return $relative;
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
		if ($this->get_path($attachment_id) === null) {
			return null;
		}
		return $this->url_from_relative($relative);
	}

	/**
	 * Best JPEG URL for a requested image size (sized companion when present, else full).
	 *
	 * @param string|int[] $size Size name or dimensions.
	 */
	public function get_url_for_size(int $attachment_id, $size = 'full'): ?string {
		$size_name = is_string($size) ? $size : 'full';
		if ($size_name !== 'full' && $size_name !== 'fullsize') {
			$map = $this->get_sizes_map($attachment_id);
			if (isset($map[ $size_name ])) {
				$path = $this->resolve_under_uploads((string) $map[ $size_name ]);
				if ($path !== null && is_readable($path)) {
					$url = $this->url_from_relative((string) $map[ $size_name ]);
					if ($url !== null) {
						return $url;
					}
				}
			}
		}
		return $this->get_url($attachment_id);
	}

	/**
	 * JPEG srcset candidates from full + sized companions.
	 *
	 * @return array{srcset: string, sizes: string}|null
	 */
	public function get_fallback_srcset(int $attachment_id, $size = 'full'): ?array {
		$info = $this->get_info($attachment_id);
		if ($info === null) {
			return null;
		}

		$candidates = [];
		if ($info['width'] > 0) {
			$candidates[ $info['width'] ] = $info['url'];
		} else {
			$candidates[ 0 ] = $info['url'];
		}

		$map = $this->get_sizes_map($attachment_id);
		foreach ($map as $relative) {
			$path = $this->resolve_under_uploads((string) $relative);
			if ($path === null || ! is_readable($path)) {
				continue;
			}
			$url = $this->url_from_relative((string) $relative);
			if ($url === null) {
				continue;
			}
			$dims = @getimagesize($path);
			$w    = is_array($dims) ? (int) ($dims[0] ?? 0) : 0;
			if ($w > 0) {
				$candidates[ $w ] = $url;
			}
		}

		if ($candidates === []) {
			return null;
		}

		ksort($candidates, SORT_NUMERIC);
		$parts = [];
		foreach ($candidates as $w => $url) {
			// Raw URLs; caller escapes for attribute context (avoid double-encoding).
			if ($w > 0) {
				$parts[] = $url . ' ' . $w . 'w';
			} else {
				$parts[] = $url;
			}
		}
		$srcset = implode(', ', $parts);
		if ($srcset === '') {
			return null;
		}

		$size_name = is_string($size) ? $size : 'full';
		$sizes     = wp_get_attachment_image_sizes($attachment_id, $size_name);
		if (! is_string($sizes) || $sizes === '') {
			$sizes = '(max-width: 100vw) 100vw';
		}

		return [
			'srcset' => $srcset,
			'sizes'  => $sizes,
		];
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
		$this->delete_sized_companions($attachment_id);

		$path = $this->get_path($attachment_id);
		if ($path !== null && is_file($path)) {
			wp_delete_file($path);
		}
		delete_post_meta($attachment_id, MetaKeys::JPEG_RELATIVE);
		delete_post_meta($attachment_id, MetaKeys::JPEG_WIDTH);
		delete_post_meta($attachment_id, MetaKeys::JPEG_HEIGHT);
	}

	private function delete_sized_companions(int $attachment_id): void {
		$map = $this->get_sizes_map($attachment_id);
		foreach ($map as $relative) {
			$path = $this->resolve_under_uploads((string) $relative);
			if ($path !== null && is_file($path)) {
				wp_delete_file($path);
			}
		}
		delete_post_meta($attachment_id, MetaKeys::JPEG_SIZES);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_sizes_map(int $attachment_id): array {
		$raw = get_post_meta($attachment_id, MetaKeys::JPEG_SIZES, true);
		if (! is_string($raw) || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (! is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $name => $relative) {
			if (! is_string($name) || ! is_string($relative) || $relative === '') {
				continue;
			}
			$key = sanitize_key($name);
			if ($key === '') {
				continue;
			}
			$out[ $key ] = $relative;
		}
		return $out;
	}

	/**
	 * @param array<string, string> $map
	 */
	private function persist_sizes_map(int $attachment_id, array $map): void {
		if ($map === []) {
			delete_post_meta($attachment_id, MetaKeys::JPEG_SIZES);
			return;
		}
		update_post_meta($attachment_id, MetaKeys::JPEG_SIZES, wp_json_encode($map));
	}

	private function url_from_relative(string $relative): ?string {
		$uploads = wp_get_upload_dir();
		if (! empty($uploads['error']) || empty($uploads['baseurl'])) {
			return null;
		}
		$url = trailingslashit((string) $uploads['baseurl']) . ltrim(str_replace('\\', '/', $relative), '/');
		$url = esc_url_raw($url);
		return $url !== '' ? $url : null;
	}

	/**
	 * Whether the attachment is (or is on disk as) AVIF/WebP.
	 * Prefers the attached file when post mime lags during upload finalize.
	 */
	public function attachment_is_modern_image(int $attachment_id): bool {
		$mime = $this->resolve_attachment_mime($attachment_id);
		return in_array($mime, [ 'image/avif', 'image/webp' ], true);
	}

	/**
	 * Best-effort MIME for an attachment (post meta, then on-disk filetype).
	 */
	public function resolve_attachment_mime(int $attachment_id): string {
		$mime = get_post_mime_type($attachment_id);
		if (is_string($mime) && in_array($mime, [ 'image/avif', 'image/webp' ], true)) {
			return $mime;
		}

		$path = get_attached_file($attachment_id, true);
		if (is_string($path) && $path !== '' && is_readable($path)) {
			$check = wp_check_filetype($path);
			$file_mime = isset($check['type']) ? (string) $check['type'] : '';
			if ($file_mime !== '') {
				return $file_mime;
			}
			$ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
			if ($ext === 'avif') {
				return 'image/avif';
			}
			if ($ext === 'webp') {
				return 'image/webp';
			}
			if ($ext === 'jpg' || $ext === 'jpeg' || $ext === 'jpe') {
				return 'image/jpeg';
			}
			if ($ext === 'png') {
				return 'image/png';
			}
		}

		return is_string($mime) ? $mime : '';
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
		return strlen($contents) >= 3
			&& ord($contents[0]) === 0xFF
			&& ord($contents[1]) === 0xD8
			&& ord($contents[2]) === 0xFF;
	}
}
