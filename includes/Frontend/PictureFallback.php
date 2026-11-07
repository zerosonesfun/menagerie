<?php
/**
 * Wrap attachment images in <picture> when a JPEG companion exists.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Frontend;

use Menagerie\Settings\Registry;
use Menagerie\Upload\JpegCompanion;

if (! defined('ABSPATH')) {
	exit;
}

final class PictureFallback {
	public function __construct(
		private Registry $registry,
		private JpegCompanion $companion
	) {
	}

	public function register(): void {
		add_filter('wp_get_attachment_image', [$this, 'filter_attachment_image'], 10, 5);
		add_filter('wp_content_img_tag', [$this, 'filter_content_img_tag'], 10, 3);
	}

	/**
	 * @param string       $html HTML.
	 * @param int          $attachment_id ID.
	 * @param string|int[] $size Size.
	 * @param bool         $icon Icon.
	 * @param array<string, mixed> $attr Attributes.
	 */
	public function filter_attachment_image($html, $attachment_id, $size, $icon, $attr): string {
		unset($icon, $attr);
		if (! is_string($html) || $html === '') {
			return is_string($html) ? $html : '';
		}
		return $this->maybe_wrap($html, (int) $attachment_id, $size);
	}

	/**
	 * @param string $filtered_image Img tag.
	 * @param string $context Context.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function filter_content_img_tag($filtered_image, $context, $attachment_id): string {
		unset($context);
		if (! is_string($filtered_image) || $filtered_image === '') {
			return is_string($filtered_image) ? $filtered_image : '';
		}
		return $this->maybe_wrap($filtered_image, (int) $attachment_id, null);
	}

	/**
	 * @param string|int[]|null $size Image size, or null to infer from markup.
	 */
	private function maybe_wrap(string $html, int $attachment_id, $size = 'full'): string {
		$settings = $this->registry->get();
		if (empty($settings['picture_fallback']) || empty($settings['jpeg_companion'])) {
			return $html;
		}
		if ($attachment_id <= 0) {
			return $html;
		}

		$mime = get_post_mime_type($attachment_id);
		if (! is_string($mime) || ! in_array($mime, [ 'image/avif', 'image/webp' ], true)) {
			return $html;
		}

		$info = $this->companion->get_info($attachment_id);
		if ($info === null) {
			return $html;
		}

		if (stripos($html, '<picture') !== false || stripos($html, 'data-menagerie-picture') !== false) {
			return $html;
		}

		if (! class_exists(\WP_HTML_Tag_Processor::class)) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor($html);
		if (! $processor->next_tag('img')) {
			return $html;
		}

		if ($size === null) {
			$size = $this->infer_size_from_img($processor);
		}

		$existing_srcset = $processor->get_attribute('srcset');
		$existing_sizes  = $processor->get_attribute('sizes');

		$srcset = wp_get_attachment_image_srcset($attachment_id, $size);
		$sizes  = wp_get_attachment_image_sizes($attachment_id, $size);
		if ((! is_string($srcset) || $srcset === '') && is_string($existing_srcset) && $existing_srcset !== '') {
			$srcset = $existing_srcset;
		}
		if ((! is_string($sizes) || $sizes === '') && is_string($existing_sizes) && $existing_sizes !== '') {
			$sizes = $existing_sizes;
		}
		if (! is_string($srcset) || $srcset === '') {
			$src = $processor->get_attribute('src');
			if (is_string($src) && $src !== '') {
				$srcset = $src;
			} else {
				return $html;
			}
		}

		$attrs = [
			'alt'          => $processor->get_attribute('alt'),
			'class'        => $processor->get_attribute('class'),
			'width'        => $processor->get_attribute('width'),
			'height'       => $processor->get_attribute('height'),
			'loading'      => $processor->get_attribute('loading'),
			'decoding'     => $processor->get_attribute('decoding'),
			'fetchpriority'=> $processor->get_attribute('fetchpriority'),
			'style'        => $processor->get_attribute('style'),
			'id'           => $processor->get_attribute('id'),
		];

		$img = '<img src="' . esc_url($info['url']) . '" data-menagerie-picture="1"';
		foreach ($attrs as $name => $value) {
			if ($value === null || $value === false) {
				continue;
			}
			// Preserve empty alt="" for decorative images; skip other empty attrs.
			if ($value === '' && $name !== 'alt') {
				continue;
			}
			$img .= ' ' . esc_attr((string) $name) . '="' . esc_attr((string) $value) . '"';
		}
		$img .= ' />';

		$source = '<source type="' . esc_attr($mime) . '" srcset="' . esc_attr($srcset) . '"';
		if (is_string($sizes) && $sizes !== '') {
			$source .= ' sizes="' . esc_attr($sizes) . '"';
		}
		$source .= ' />';

		return '<picture>' . $source . $img . '</picture>';
	}

	/**
	 * Infer a registered image size from class="… size-medium …".
	 *
	 * @return string Size name.
	 */
	private function infer_size_from_img(\WP_HTML_Tag_Processor $processor): string {
		$class = $processor->get_attribute('class');
		if (! is_string($class) || $class === '') {
			return 'full';
		}
		if (! preg_match('/\bsize-([a-zA-Z0-9_-]+)\b/', $class, $matches)) {
			return 'full';
		}
		$candidate = $matches[1];
		if ($candidate === 'full' || $candidate === 'fullsize') {
			return 'full';
		}
		$registered = get_intermediate_image_sizes();
		if (in_array($candidate, $registered, true)) {
			return $candidate;
		}
		return 'full';
	}
}
