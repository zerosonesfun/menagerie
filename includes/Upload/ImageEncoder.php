<?php
/**
 * Shared server-side image encode helpers (GD / Imagick via WP_Image_Editor).
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

if (! defined('ABSPATH')) {
	exit;
}

final class ImageEncoder {
	/**
	 * Whether the active image editor can encode the given MIME type.
	 */
	public function supports_output_mime(string $mime): bool {
		return (bool) wp_image_editor_supports([ 'mime_type' => $mime ]);
	}

	public function image_has_alpha_channel(string $file, string $mime): bool {
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

		/*
		 * Formats that commonly carry alpha: if Imagick/getimagesize/GD could not decide
		 * (typical for AVIF without Imagick), assume alpha may exist so auto mode does not
		 * flatten to JPEG by mistake.
		 */
		if (in_array($mime, [ 'image/avif', 'image/png', 'image/webp' ], true)) {
			return true;
		}

		return false;
	}

	/**
	 * Sample pixels for non-opaque alpha (truecolor) or palette transparency.
	 *
	 * @return bool|null False = opaque, true = has transparency, null = could not read
	 */
	public function gd_sample_has_transparency(string $file, string $mime): ?bool {
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
	public function try_encode(string $source_file, array $mimes, int $quality, int $max_w, int $max_h): ?array {
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
	public function encode_once_to_mime(
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

	public function extension_for_mime(string $mime): ?string {
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
	 * Map UI target key to MIME type.
	 */
	public function mime_for_target(string $target): ?string {
		switch (strtolower($target)) {
			case 'avif':
				return 'image/avif';
			case 'webp':
				return 'image/webp';
			case 'png':
				return 'image/png';
			case 'jpg':
			case 'jpeg':
				return 'image/jpeg';
			default:
				return null;
		}
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	public function delete_intermediate_files(string $main_file, array $metadata): void {
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

	/**
	 * @param bool $mark_server_fallback When true, sets _menagerie_server_fallback (upload fallback only).
	 */
	public function write_success_meta(
		int $attachment_id,
		int $original_bytes,
		int $optimized_bytes,
		string $mime_out,
		bool $mark_server_fallback = true
	): void {
		update_post_meta($attachment_id, MetaKeys::PROCESSED, '1');
		update_post_meta($attachment_id, MetaKeys::ORIGINAL_BYTES, $original_bytes);
		update_post_meta($attachment_id, MetaKeys::OPTIMIZED_BYTES, $optimized_bytes);
		update_post_meta($attachment_id, MetaKeys::MIME_OUT, sanitize_mime_type($mime_out));
		update_post_meta($attachment_id, MetaKeys::PROCESSED_AT, time());
		if ($mark_server_fallback) {
			update_post_meta($attachment_id, MetaKeys::SERVER_FALLBACK, '1');
		} else {
			delete_post_meta($attachment_id, MetaKeys::SERVER_FALLBACK);
		}
	}
}
