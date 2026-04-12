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
		private Registry $registry
	) {
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
		if (self::$recursing) {
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
		if (self::$recursing) {
			return $metadata;
		}

		if (! is_array($metadata)) {
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

		$has_alpha = $this->image_has_alpha_channel($path, $mime_in);
		$types     = $this->pick_output_mimes(
			(string) ($settings['format_mode'] ?? 'auto'),
			$has_alpha,
			! empty($settings['preserve_transparency'])
		);

		$result = $this->try_encode(
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
		$new_metadata    = wp_generate_attachment_metadata($id, $new_path);
		self::$recursing = false;

		if (! is_array($new_metadata) || ! isset($new_metadata['file'])) {
			if (is_array($new_metadata)) {
				$this->delete_intermediate_files($new_path, $new_metadata);
			}
			if ($new_path !== $path && is_file($new_path)) {
				@unlink($new_path);
			}
			return $metadata;
		}

		$this->delete_intermediate_files($path, $metadata);

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

		$this->write_success_meta($id, $original_bytes, $new_size, $new_mime);

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

	private function image_has_alpha_channel(string $file, string $mime): bool {
		if ($mime === 'image/jpeg') {
			return false;
		}
		if (class_exists(\Imagick::class, false)) {
			try {
				$i = new \Imagick($file);
				$ac = $i->getImageAlphaChannel();
				$i->clear();
				$i->destroy();
				$undefined = defined('Imagick::ALPHACHANNEL_UNDEFINED') ? constant('Imagick::ALPHACHANNEL_UNDEFINED') : null;
				$off       = defined('Imagick::ALPHACHANNEL_OFF') ? constant('Imagick::ALPHACHANNEL_OFF') : null;
				if ($undefined !== null && $ac === $undefined) {
					return false;
				}
				if ($off !== null && $ac === $off) {
					return false;
				}

				return $ac !== false && $ac !== 0;
			} catch (\Throwable $e) {
				// Continue with heuristics.
			}
		}

		$info = @getimagesize($file);
		if (is_array($info) && isset($info['channels']) && (int) $info['channels'] === 4) {
			return true;
		}

		$gd = $this->gd_sample_has_transparency($file, $mime);
		if ($gd !== null) {
			return $gd;
		}

		return false;
	}

	/**
	 * Sample pixels for non-opaque alpha (truecolor) or palette transparency.
	 *
	 * @return bool|null False = opaque, true = has transparency, null = could not read
	 */
	private function gd_sample_has_transparency(string $file, string $mime): ?bool {
		if ($mime !== 'image/png' && $mime !== 'image/webp') {
			return null;
		}
		if (! function_exists('imagecreatefromstring')) {
			return null;
		}
		$data = @file_get_contents($file);
		if ($data === false || $data === '') {
			return null;
		}
		$im = @imagecreatefromstring($data);
		if (! is_gd_image($im)) {
			return null;
		}
		imagealphablending($im, false);
		imagesavealpha($im, true);

		if (! imageistruecolor($im)) {
			$t = imagecolortransparent($im);
			imagedestroy($im);
			return $t >= 0;
		}

		$w = imagesx($im);
		$h = imagesy($im);
		if ($w < 1 || $h < 1) {
			imagedestroy($im);
			return null;
		}
		$step_x = max(1, (int) ($w / 24));
		$step_y = max(1, (int) ($h / 24));
		for ($y = 0; $y < $h; $y += $step_y) {
			for ($x = 0; $x < $w; $x += $step_x) {
				$c = imagecolorat($im, $x, $y);
				$a = ($c >> 24) & 0x7F;
				if ($a > 0) {
					imagedestroy($im);
					return true;
				}
			}
		}
		imagedestroy($im);
		return false;
	}

	/**
	 * @param list<string> $mimes
	 * @return array{path: string, mime: string, bytes: int}|null
	 */
	private function try_encode(string $source_file, array $mimes, int $quality, int $max_w, int $max_h): ?array {
		$dir = dirname($source_file);
		if ($dir === '' || $dir === '.' || ! is_dir($dir) || ! is_writable($dir)) {
			$upload_dir = wp_upload_dir();
			if (! empty($upload_dir['error'])) {
				return null;
			}
			$dir = $upload_dir['path'];
			if ($dir === '' || ! is_dir($dir) || ! is_writable($dir)) {
				return null;
			}
		}

		$base = pathinfo($source_file, PATHINFO_FILENAME);
		$base = sanitize_file_name($base);
		if ($base === '') {
			$base = 'image';
		}

		foreach ($mimes as $mime) {
			/*
			 * AVIF / WebP: two attempts (fresh editor each time) — occasional Imagick/lib failures under memory pressure;
			 * matches client-side WASM retries for those codecs.
			 */
			$attempts = ($mime === 'image/avif' || $mime === 'image/webp') ? 2 : 1;
			for ($a = 0; $a < $attempts; $a++) {
				$out = $this->encode_once_to_mime($source_file, $mime, $quality, $max_w, $max_h, $dir, $base);
				if ($out !== null) {
					return $out;
				}
			}
		}

		return null;
	}

	/**
	 * @return array{path: string, mime: string, bytes: int}|null
	 */
	private function encode_once_to_mime(
		string $source_file,
		string $mime,
		int $quality,
		int $max_w,
		int $max_h,
		string $dir,
		string $base
	): ?array {
		$editor = wp_get_image_editor($source_file);
		if (is_wp_error($editor)) {
			return null;
		}
		if (! $editor->supports_mime_type($mime)) {
			return null;
		}

		if (method_exists($editor, 'maybe_exif_rotate')) {
			$editor->maybe_exif_rotate();
		}

		$editor->set_quality(min(100, max(1, $quality)));

		$dim = $editor->get_size();
		if (is_wp_error($dim)) {
			return null;
		}
		$w = (int) ($dim['width'] ?? 0);
		$h = (int) ($dim['height'] ?? 0);
		if ($w < 1 || $h < 1) {
			return null;
		}

		$scale = min($max_w / $w, $max_h / $h, 1.0);
		if ($scale < 1.0) {
			$nw = max(1, (int) round($w * $scale));
			$nh = max(1, (int) round($h * $scale));
			$resized = $editor->resize($nw, $nh, false);
			if (is_wp_error($resized)) {
				return null;
			}
		}

		$ext = $this->extension_for_mime($mime);
		if ($ext === null) {
			return null;
		}

		$file_name = $base . '.' . $ext;
		$file_name = wp_unique_filename($dir, $file_name);
		$dest      = path_join($dir, $file_name);

		$saved = $editor->save($dest, $mime);
		if (is_wp_error($saved)) {
			return null;
		}
		$out_path = isset($saved['path']) ? (string) $saved['path'] : $dest;
		if (! is_readable($out_path)) {
			return null;
		}
		$bytes = @filesize($out_path);
		if ($bytes === false) {
			@unlink($out_path);
			return null;
		}
		$out_mime = isset($saved['mime-type']) ? (string) $saved['mime-type'] : $mime;

		return [
			'path'  => $out_path,
			'mime'  => $out_mime,
			'bytes' => (int) $bytes,
		];
	}

	private function extension_for_mime(string $mime): ?string {
		switch ($mime) {
			case 'image/jpeg':
				return 'jpg';
			case 'image/png':
				return 'png';
			case 'image/webp':
				return 'webp';
			case 'image/avif':
				return 'avif';
			default:
				return null;
		}
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	private function delete_intermediate_files(string $main_file, array $metadata): void {
		$dir = dirname($main_file);
		if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $info) {
				if (! is_array($info) || empty($info['file'])) {
					continue;
				}
				$p = path_join($dir, (string) $info['file']);
				if ($p !== $main_file && is_file($p)) {
					@unlink($p);
				}
			}
		}
		if (! empty($metadata['original_image']) && is_string($metadata['original_image'])) {
			$p = path_join($dir, $metadata['original_image']);
			if ($p !== $main_file && is_file($p)) {
				@unlink($p);
			}
		}
	}

	private function write_success_meta(int $attachment_id, int $original_bytes, int $optimized_bytes, string $mime_out): void {
		update_post_meta($attachment_id, MetaKeys::PROCESSED, '1');
		update_post_meta($attachment_id, MetaKeys::ORIGINAL_BYTES, $original_bytes);
		update_post_meta($attachment_id, MetaKeys::OPTIMIZED_BYTES, $optimized_bytes);
		update_post_meta($attachment_id, MetaKeys::MIME_OUT, sanitize_mime_type($mime_out));
		update_post_meta($attachment_id, MetaKeys::PROCESSED_AT, time());
		update_post_meta($attachment_id, MetaKeys::SERVER_FALLBACK, '1');
	}
}
