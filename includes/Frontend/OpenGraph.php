<?php
/**
 * Prefer JPEG companions for Open Graph image tags.
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

final class OpenGraph {
	private bool $printed_native = false;

	private bool $seo_plugin_active = false;

	/** @var array{url: string, width: int, height: int}|null */
	private ?array $active_companion = null;

	public function __construct(
		private Registry $registry,
		private JpegCompanion $companion
	) {
	}

	public function register(): void {
		add_action('wp', [$this, 'detect_seo_plugins'], 1);
		add_action('wp_head', [$this, 'maybe_print_native'], 5);

		// Yoast SEO.
		add_filter('wpseo_opengraph_image', [$this, 'filter_og_image_url'], 20);
		add_filter('wpseo_opengraph_image_width', [$this, 'filter_yoast_image_width'], 20);
		add_filter('wpseo_opengraph_image_height', [$this, 'filter_yoast_image_height'], 20);
		add_filter('wpseo_opengraph_image_type', [$this, 'filter_yoast_image_type'], 20);
		add_filter('wpseo_opengraph_type', [$this, 'mark_seo_active'], 1);

		// Rank Math.
		add_filter('rank_math/opengraph/facebook/image', [$this, 'filter_og_image_url'], 20);
		add_filter('rank_math/opengraph/twitter/image', [$this, 'filter_og_image_url'], 20);

		// All in One SEO.
		add_filter('aioseo_facebook_tags', [$this, 'filter_aioseo_facebook_tags'], 20);
		add_filter('aioseo_twitter_tags', [$this, 'filter_aioseo_twitter_tags'], 20);

		// The SEO Framework (v5 meta render bag).
		add_filter('the_seo_framework_meta_render_data', [$this, 'filter_tsf_meta_render_data'], 20);
	}

	public function detect_seo_plugins(): void {
		$this->seo_plugin_active = defined('WPSEO_VERSION')
			|| class_exists('RankMath', false)
			|| defined('RANK_MATH_VERSION')
			|| defined('AIOSEO_VERSION')
			|| defined('THE_SEO_FRAMEWORK_VERSION')
			|| class_exists('\\The_SEO_Framework\\Load', false);
	}

	/**
	 * Replace an SEO plugin's chosen image URL only when that URL is a modern
	 * attachment that has a JPEG companion — never swap in an unrelated companion.
	 *
	 * @param mixed $url Image URL.
	 * @return mixed
	 */
	public function filter_og_image_url($url) {
		$this->seo_plugin_active = true;
		if (! $this->og_enabled()) {
			$this->active_companion = null;
			return $url;
		}

		$url_string = is_string($url) ? $url : '';
		$info       = $this->companion_for_url($url_string);
		if ($info === null) {
			$this->active_companion = null;
			return $url;
		}

		$this->active_companion = $info;
		return $this->absolute_url($info['url']);
	}

	/**
	 * @param mixed $width Width.
	 * @return mixed
	 */
	public function filter_yoast_image_width($width) {
		if ($this->active_companion === null || $this->active_companion['width'] < 1) {
			return $width;
		}
		return $this->active_companion['width'];
	}

	/**
	 * @param mixed $height Height.
	 * @return mixed
	 */
	public function filter_yoast_image_height($height) {
		if ($this->active_companion === null || $this->active_companion['height'] < 1) {
			return $height;
		}
		return $this->active_companion['height'];
	}

	/**
	 * @param mixed $type MIME type.
	 * @return mixed
	 */
	public function filter_yoast_image_type($type) {
		if ($this->active_companion === null) {
			return $type;
		}
		return 'image/jpeg';
	}

	/**
	 * @param mixed $type Type.
	 * @return mixed
	 */
	public function mark_seo_active($type) {
		$this->seo_plugin_active = true;
		return $type;
	}

	/**
	 * @param mixed $tags AIOSEO Facebook meta tags.
	 * @return mixed
	 */
	public function filter_aioseo_facebook_tags($tags) {
		$this->seo_plugin_active = true;
		if (! $this->og_enabled() || ! is_array($tags)) {
			return $tags;
		}

		$existing = isset($tags['og:image']) && is_string($tags['og:image']) ? $tags['og:image'] : '';
		$info     = $existing !== ''
			? $this->companion_for_url($existing)
			: $this->resolve_featured_companion();
		if ($info === null) {
			return $tags;
		}

		$url = $this->absolute_url($info['url']);
		$tags['og:image'] = $url;
		$tags['og:image:secure_url'] = $url;
		$tags['og:image:type'] = 'image/jpeg';
		if ($info['width'] > 0) {
			$tags['og:image:width'] = (string) $info['width'];
		}
		if ($info['height'] > 0) {
			$tags['og:image:height'] = (string) $info['height'];
		}
		return $tags;
	}

	/**
	 * @param mixed $tags AIOSEO Twitter meta tags.
	 * @return mixed
	 */
	public function filter_aioseo_twitter_tags($tags) {
		$this->seo_plugin_active = true;
		if (! $this->og_enabled() || ! is_array($tags)) {
			return $tags;
		}

		$existing = isset($tags['twitter:image']) && is_string($tags['twitter:image']) ? $tags['twitter:image'] : '';
		$info     = $existing !== ''
			? $this->companion_for_url($existing)
			: $this->resolve_featured_companion();
		if ($info === null) {
			return $tags;
		}

		$tags['twitter:image'] = $this->absolute_url($info['url']);
		return $tags;
	}

	/**
	 * @param mixed $data TSF meta render data.
	 * @return mixed
	 */
	public function filter_tsf_meta_render_data($data) {
		$this->seo_plugin_active = true;
		if (! $this->og_enabled() || ! is_array($data)) {
			return $data;
		}

		$existing_url = '';
		if (isset($data['og:image:0']) && is_array($data['og:image:0'])) {
			$attrs = $data['og:image:0']['attributes'] ?? null;
			if (is_array($attrs) && isset($attrs['content']) && is_string($attrs['content'])) {
				$existing_url = $attrs['content'];
			}
		}

		$info = $existing_url !== ''
			? $this->companion_for_url($existing_url)
			: $this->resolve_featured_companion();
		if ($info === null) {
			return $data;
		}

		$url = $this->absolute_url($info['url']);
		if (! isset($data['og:image:0']) || ! is_array($data['og:image:0'])) {
			$data['og:image:0'] = [
				'tag'        => 'meta',
				'attributes' => [
					'property' => 'og:image',
					'content'  => $url,
				],
			];
		} else {
			if (! isset($data['og:image:0']['attributes']) || ! is_array($data['og:image:0']['attributes'])) {
				$data['og:image:0']['attributes'] = [];
			}
			$data['og:image:0']['attributes']['content'] = $url;
			if (! isset($data['og:image:0']['attributes']['property'])) {
				$data['og:image:0']['attributes']['property'] = 'og:image';
			}
			if (! isset($data['og:image:0']['tag'])) {
				$data['og:image:0']['tag'] = 'meta';
			}
		}

		return $data;
	}

	public function maybe_print_native(): void {
		if ($this->printed_native || $this->seo_plugin_active) {
			return;
		}
		if (! $this->og_enabled()) {
			return;
		}

		$info = $this->resolve_companion_for_queried();
		if ($info === null) {
			return;
		}

		$this->printed_native = true;
		$url = esc_url($this->absolute_url($info['url']));
		echo '<meta property="og:image" content="' . $url . '" />' . "\n";
		echo '<meta property="og:image:type" content="image/jpeg" />' . "\n";
		if ($info['width'] > 0) {
			echo '<meta property="og:image:width" content="' . esc_attr((string) $info['width']) . '" />' . "\n";
		}
		if ($info['height'] > 0) {
			echo '<meta property="og:image:height" content="' . esc_attr((string) $info['height']) . '" />' . "\n";
		}
		$alt = $this->resolve_alt_for_queried();
		if ($alt !== '') {
			echo '<meta property="og:image:alt" content="' . esc_attr($alt) . '" />' . "\n";
		}
	}

	private function og_enabled(): bool {
		$settings = $this->registry->get();
		return ! empty($settings['og_jpeg_companion']) && ! empty($settings['jpeg_companion']);
	}

	/**
	 * Companion for the attachment represented by an existing OG image URL.
	 *
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function companion_for_url(string $url): ?array {
		if ($url === '') {
			return null;
		}
		$id = $this->attachment_id_from_url($url);
		if ($id <= 0) {
			return null;
		}
		return $this->companion->get_info($id);
	}

	/**
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function resolve_featured_companion(): ?array {
		if (! is_singular()) {
			return null;
		}
		$post_id = get_queried_object_id();
		if ($post_id <= 0) {
			return null;
		}
		$thumb_id = (int) get_post_thumbnail_id($post_id);
		if ($thumb_id <= 0) {
			return null;
		}
		return $this->companion->get_info($thumb_id);
	}

	/**
	 * Featured companion first, else first content image with a companion (native OG only).
	 *
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function resolve_companion_for_queried(): ?array {
		if (! is_singular()) {
			return null;
		}

		$featured = $this->resolve_featured_companion();
		if ($featured !== null) {
			return $featured;
		}

		$post_id = get_queried_object_id();
		if ($post_id <= 0) {
			return null;
		}

		$content = (string) get_post_field('post_content', $post_id);
		if ($content === '') {
			return null;
		}

		if (preg_match_all('/wp-image-(\d+)/', $content, $matches)) {
			foreach ($matches[1] as $raw_id) {
				$id = (int) $raw_id;
				if ($id <= 0) {
					continue;
				}
				$info = $this->companion->get_info($id);
				if ($info !== null) {
					return $info;
				}
			}
		}

		return null;
	}

	private function attachment_id_from_url(string $url): int {
		$id = (int) attachment_url_to_postid($url);
		if ($id > 0) {
			return $id;
		}

		// Sized derivatives: image-300x200.webp → image.webp
		$stripped = preg_replace('/-\d+x\d+(?=\.[a-zA-Z0-9]+(?:\?|$))/', '', $url);
		if (is_string($stripped) && $stripped !== $url) {
			$id = (int) attachment_url_to_postid($stripped);
			if ($id > 0) {
				return $id;
			}
		}

		return 0;
	}

	private function resolve_alt_for_queried(): string {
		$post_id = get_queried_object_id();
		if ($post_id <= 0) {
			return '';
		}
		$thumb_id = (int) get_post_thumbnail_id($post_id);
		if ($thumb_id > 0) {
			$alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
			if (is_string($alt) && $alt !== '') {
				return $alt;
			}
		}
		$title = get_the_title($post_id);
		return is_string($title) ? $title : '';
	}

	private function absolute_url(string $url): string {
		if (str_starts_with($url, '//')) {
			$url = 'https:' . $url;
		}
		$scheme = 'http';
		if (is_ssl() || (function_exists('wp_is_using_https') && wp_is_using_https())) {
			$scheme = 'https';
		}
		return set_url_scheme($url, $scheme);
	}
}
