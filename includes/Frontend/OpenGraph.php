<?php
/**
 * Prefer JPEG companions for Open Graph image tags.
 *
 * Rewrites existing og/twitter image metas in place (SEO plugins, Jetpack,
 * themes). Native tags are emitted only when nothing else printed og:image.
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
	private bool $buffering_page = false;

	private bool $saw_og_image = false;

	/** @var array{url: string, width: int, height: int}|null */
	private ?array $active_companion = null;

	/** Meta keys whose content is an image URL Menagerie may rewrite. */
	private const IMAGE_URL_META_KEYS = [
		'og:image',
		'og:image:secure_url',
		'og:image:url',
		'twitter:image',
		'twitter:image:src',
	];

	/** Meta keys for type/dimensions updated after a successful URL swap. */
	private const IMAGE_ATTR_META_KEYS = [
		'og:image:type',
		'og:image:width',
		'og:image:height',
	];

	public function __construct(
		private Registry $registry,
		private JpegCompanion $companion
	) {
	}

	public function register(): void {
		// Full-page buffer so OG metas are rewritten even when cache/SEO plugins nest wp_head buffers.
		add_action('template_redirect', [$this, 'start_page_buffer'], 0);

		// Yoast SEO.
		add_filter('wpseo_opengraph_image', [$this, 'filter_og_image_url'], 20);
		add_filter('wpseo_opengraph_image_width', [$this, 'filter_yoast_image_width'], 20);
		add_filter('wpseo_opengraph_image_height', [$this, 'filter_yoast_image_height'], 20);
		add_filter('wpseo_opengraph_image_type', [$this, 'filter_yoast_image_type'], 20);

		// Rank Math.
		add_filter('rank_math/opengraph/facebook/image', [$this, 'filter_og_image_url'], 20);
		add_filter('rank_math/opengraph/twitter/image', [$this, 'filter_og_image_url'], 20);

		// All in One SEO.
		add_filter('aioseo_facebook_tags', [$this, 'filter_aioseo_facebook_tags'], 20);
		add_filter('aioseo_twitter_tags', [$this, 'filter_aioseo_twitter_tags'], 20);

		// The SEO Framework (v5 meta render bag).
		add_filter('the_seo_framework_meta_render_data', [$this, 'filter_tsf_meta_render_data'], 20);

		// Jetpack (also used by Architrave Extras sharing / link previews).
		add_filter('jetpack_open_graph_tags', [$this, 'filter_jetpack_open_graph_tags'], 20, 2);

		// SEOPress (emits full meta HTML via these filters; used on wilcosky.com).
		add_filter('seopress_social_og_thumb', [$this, 'filter_seopress_thumb_html'], 20);
		add_filter('seopress_social_twitter_card_thumb', [$this, 'filter_seopress_thumb_html'], 20);
	}

	/**
	 * Capture the full HTML response and rewrite image metas before send.
	 */
	public function start_page_buffer(): void {
		if (! $this->og_enabled() || is_admin() || $this->buffering_page) {
			return;
		}
		if (wp_doing_ajax() || wp_doing_cron() || is_feed()) {
			return;
		}
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return;
		}
		if (defined('WP_CLI') && WP_CLI) {
			return;
		}

		$this->buffering_page = true;
		ob_start([ $this, 'rewrite_page_buffer' ]);
	}

	/**
	 * @param string $html Full page HTML.
	 */
	public function rewrite_page_buffer(string $html): string {
		$this->buffering_page = false;
		if ($html === '' || ! $this->og_enabled()) {
			return $html;
		}

		$html = $this->rewrite_head_image_metas($html);

		if (! $this->saw_og_image) {
			$native = $this->native_tags_html();
			if ($native !== '') {
				$replaced = preg_replace('/<\/head>/i', $native . '</head>', $html, 1);
				if (is_string($replaced)) {
					$html = $replaced;
				} else {
					$html .= $native;
				}
			}
		}

		return $html;
	}

	/**
	 * SEOPress passes assembled OG/Twitter image meta HTML (not a bare URL).
	 *
	 * @param mixed $html Meta HTML.
	 * @return mixed
	 */
	public function filter_seopress_thumb_html($html) {
		if (! $this->og_enabled() || ! is_string($html) || $html === '') {
			return $html;
		}
		return $this->rewrite_head_image_metas($html);
	}

	/**
	 * Replace an SEO plugin's chosen image URL only when that URL is a modern
	 * attachment that has a JPEG companion - never swap in an unrelated companion.
	 *
	 * @param mixed $url Image URL.
	 * @return mixed
	 */
	public function filter_og_image_url($url) {
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
	 * Jetpack / Architrave Extras: rewrite only image-related tag values.
	 *
	 * @param mixed                $tags Open Graph tags.
	 * @param array<string, mixed> $args Image size args (unused).
	 * @return mixed
	 */
	public function filter_jetpack_open_graph_tags($tags, $args = []) {
		unset($args);
		if (! $this->og_enabled() || ! is_array($tags)) {
			return $tags;
		}

		$existing = $this->first_image_url_from_tag_value($tags['og:image'] ?? null);
		if ($existing === '') {
			$existing = $this->first_image_url_from_tag_value($tags['twitter:image'] ?? null);
		}

		$info = $existing !== ''
			? $this->companion_for_url($existing)
			: null;
		if ($info === null) {
			return $tags;
		}

		$url = $this->absolute_url($info['url']);
		$this->active_companion = $info;

		if (isset($tags['og:image'])) {
			$tags['og:image'] = is_array($tags['og:image'])
				? $this->replace_first_url_in_list($tags['og:image'], $url)
				: $url;
		}
		if (isset($tags['og:image:secure_url'])) {
			$tags['og:image:secure_url'] = is_array($tags['og:image:secure_url'])
				? $this->replace_first_url_in_list($tags['og:image:secure_url'], $url)
				: $url;
		}
		if (isset($tags['og:image:url'])) {
			$tags['og:image:url'] = is_array($tags['og:image:url'])
				? $this->replace_first_url_in_list($tags['og:image:url'], $url)
				: $url;
		}
		if (isset($tags['twitter:image'])) {
			$tags['twitter:image'] = is_array($tags['twitter:image'])
				? $this->replace_first_url_in_list($tags['twitter:image'], $url)
				: $url;
		}
		if (isset($tags['twitter:image:src'])) {
			$tags['twitter:image:src'] = is_array($tags['twitter:image:src'])
				? $this->replace_first_url_in_list($tags['twitter:image:src'], $url)
				: $url;
		}

		$tags['og:image:type'] = 'image/jpeg';
		if ($info['width'] > 0) {
			$tags['og:image:width'] = $info['width'];
		}
		if ($info['height'] > 0) {
			$tags['og:image:height'] = $info['height'];
		}

		return $tags;
	}

	/**
	 * @param mixed $tags AIOSEO Facebook meta tags.
	 * @return mixed
	 */
	public function filter_aioseo_facebook_tags($tags) {
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

	/**
	 * Surgically replace image-related meta content attributes in a head HTML chunk.
	 *
	 * Two passes so type/width/height update even when they appear before og:image.
	 */
	private function rewrite_head_image_metas(string $html): string {
		/** @var array{url: string, width: int, height: int}|null $swap_info */
		$swap_info = null;

		// Pass 1: rewrite URL metas; remember companion from the first successful swap.
		$pass1 = preg_replace_callback(
			'/<meta\b[^>]*>/i',
			function (array $matches) use (&$swap_info): string {
				$tag = $matches[0];
				$key = $this->meta_tag_key($tag);
				if ($key === null) {
					return $tag;
				}

				if ($key === 'og:image') {
					$this->saw_og_image = true;
				}

				if (! in_array($key, self::IMAGE_URL_META_KEYS, true)) {
					return $tag;
				}

				$content = $this->meta_tag_content($tag);
				if ($content === null || $content === '') {
					return $tag;
				}

				$info = $this->companion_for_url($content);
				if ($info === null) {
					return $tag;
				}

				if ($swap_info === null) {
					$swap_info = $info;
				}

				return $this->replace_meta_content($tag, $this->absolute_url($info['url']));
			},
			$html
		);

		if (! is_string($pass1)) {
			return $html;
		}

		if ($swap_info === null) {
			return $pass1;
		}

		// Pass 2: align type/dimensions with the companion (order-independent).
		$pass2 = preg_replace_callback(
			'/<meta\b[^>]*>/i',
			function (array $matches) use ($swap_info): string {
				$tag = $matches[0];
				$key = $this->meta_tag_key($tag);
				if ($key === null || ! in_array($key, self::IMAGE_ATTR_META_KEYS, true)) {
					return $tag;
				}

				if ($key === 'og:image:type') {
					return $this->replace_meta_content($tag, 'image/jpeg');
				}
				if ($key === 'og:image:width' && $swap_info['width'] > 0) {
					return $this->replace_meta_content($tag, (string) $swap_info['width']);
				}
				if ($key === 'og:image:height' && $swap_info['height'] > 0) {
					return $this->replace_meta_content($tag, (string) $swap_info['height']);
				}

				return $tag;
			},
			$pass1
		);

		return is_string($pass2) ? $pass2 : $pass1;
	}

	/**
	 * Native JPEG companion OG tags markup when no foreign og:image was present.
	 */
	private function native_tags_html(): string {
		$info = $this->resolve_companion_for_queried();
		if ($info === null) {
			return '';
		}

		$url  = esc_url($this->absolute_url($info['url']));
		$html = '<meta property="og:image" content="' . $url . '" />' . "\n";
		$html .= '<meta property="og:image:type" content="image/jpeg" />' . "\n";
		if ($info['width'] > 0) {
			$html .= '<meta property="og:image:width" content="' . esc_attr((string) $info['width']) . '" />' . "\n";
		}
		if ($info['height'] > 0) {
			$html .= '<meta property="og:image:height" content="' . esc_attr((string) $info['height']) . '" />' . "\n";
		}
		$alt = $this->resolve_alt_for_queried();
		if ($alt !== '') {
			$html .= '<meta property="og:image:alt" content="' . esc_attr($alt) . '" />' . "\n";
		}
		return $html;
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
		if ($id > 0) {
			$info = $this->companion->get_info($id);
			if ($info !== null) {
				return $info;
			}
		}

		// attachment_url_to_postid is often unreliable; still allow a safe featured match.
		return $this->companion_for_url_via_featured($url);
	}

	/**
	 * When URL→ID fails, use the featured image companion only if basenames match.
	 *
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function companion_for_url_via_featured(string $url): ?array {
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

		$attached = get_attached_file($thumb_id, true);
		if (! is_string($attached) || $attached === '') {
			return null;
		}

		$url_path = wp_parse_url($this->normalize_lookup_url($url), PHP_URL_PATH);
		if (! is_string($url_path) || $url_path === '') {
			return null;
		}

		$url_base      = basename($url_path);
		$attached_base = basename($attached);
		if ($url_base !== $attached_base) {
			$stripped = preg_replace('/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $url_base);
			if (! is_string($stripped) || $stripped !== $attached_base) {
				return null;
			}
		}

		return $this->companion->get_info($thumb_id);
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
		$url = $this->normalize_lookup_url($url);
		if ($url === '') {
			return 0;
		}

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
			$url_for_relative = $stripped;
		} else {
			$url_for_relative = $url;
		}

		// Direct _wp_attached_file lookup (more reliable than attachment_url_to_postid).
		$relative = $this->uploads_relative_path($url_for_relative);
		if ($relative !== null) {
			$id = $this->attachment_id_from_relative($relative);
			if ($id > 0) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Path under uploads baseurl, or null when the URL is outside uploads.
	 */
	private function uploads_relative_path(string $url): ?string {
		$uploads = wp_get_upload_dir();
		if (! empty($uploads['error']) || empty($uploads['baseurl'])) {
			return null;
		}

		$baseurl = untrailingslashit((string) $uploads['baseurl']);
		$candidates = [ $baseurl ];
		$alt = set_url_scheme($baseurl, str_starts_with($baseurl, 'https://') ? 'http' : 'https');
		if (is_string($alt) && $alt !== $baseurl) {
			$candidates[] = untrailingslashit($alt);
		}

		foreach ($candidates as $base) {
			$prefix = $base . '/';
			if (str_starts_with($url, $prefix)) {
				$relative = ltrim(substr($url, strlen($prefix)), '/');
				return $relative !== '' ? $relative : null;
			}
		}

		// Path-only match under /wp-content/uploads/
		$path = wp_parse_url($url, PHP_URL_PATH);
		if (! is_string($path) || $path === '') {
			return null;
		}
		$marker = '/wp-content/uploads/';
		$pos    = strpos($path, $marker);
		if ($pos === false) {
			return null;
		}
		$relative = ltrim(substr($path, $pos + strlen($marker)), '/');
		return $relative !== '' ? $relative : null;
	}

	/**
	 * Resolve attachment ID from an uploads-relative file path (_wp_attached_file).
	 */
	private function attachment_id_from_relative(string $relative): int {
		$relative = ltrim(str_replace('\\', '/', $relative), '/');
		if ($relative === '' || str_contains($relative, '..')) {
			return 0;
		}

		global $wpdb;
		if (! isset($wpdb) || ! is_object($wpdb)) {
			return 0;
		}

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative
			)
		);
		return $id > 0 ? $id : 0;
	}

	/**
	 * Strip query/fragment so attachment_url_to_postid can match.
	 */
	private function normalize_lookup_url(string $url): string {
		$url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($url === '') {
			return '';
		}

		$parts = wp_parse_url($url);
		if (! is_array($parts) || empty($parts['path'])) {
			return $url;
		}

		$built = '';
		if (! empty($parts['scheme'])) {
			$built .= $parts['scheme'] . '://';
		} elseif (str_starts_with($url, '//')) {
			$built .= '//';
		}
		if (! empty($parts['host'])) {
			$built .= $parts['host'];
			if (! empty($parts['port'])) {
				$built .= ':' . $parts['port'];
			}
		}
		$built .= $parts['path'];

		return $built !== '' ? $built : $url;
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

	/**
	 * @param mixed $value Tag value (string or list of URLs).
	 */
	private function first_image_url_from_tag_value($value): string {
		if (is_string($value)) {
			return $value;
		}
		if (! is_array($value)) {
			return '';
		}
		foreach ($value as $item) {
			if (is_string($item) && $item !== '') {
				return $item;
			}
		}
		return '';
	}

	/**
	 * @param array<mixed> $list URL list.
	 * @return array<mixed>
	 */
	private function replace_first_url_in_list(array $list, string $url): array {
		$replaced = false;
		$out      = [];
		foreach ($list as $item) {
			if (! $replaced && is_string($item) && $item !== '') {
				$out[]    = $url;
				$replaced = true;
				continue;
			}
			$out[] = $item;
		}
		if (! $replaced) {
			$out[] = $url;
		}
		return $out;
	}

	private function meta_tag_key(string $tag): ?string {
		if (preg_match('/\bproperty\s*=\s*(["\'])([^"\']+)\1/i', $tag, $m) === 1) {
			return strtolower($m[2]);
		}
		if (preg_match('/\bname\s*=\s*(["\'])([^"\']+)\1/i', $tag, $m) === 1) {
			return strtolower($m[2]);
		}
		return null;
	}

	private function meta_tag_content(string $tag): ?string {
		if (preg_match('/\bcontent\s*=\s*(["\'])([^"\']*)\1/i', $tag, $m) === 1) {
			return html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		return null;
	}

	/**
	 * Replace the content attribute without preg_replace backreference pitfalls ($ and \).
	 */
	private function replace_meta_content(string $tag, string $content): string {
		$escaped = esc_attr($content);
		if (preg_match('/\bcontent\s*=\s*(["\'])([^"\']*)\1/i', $tag, $m, PREG_OFFSET_CAPTURE) !== 1) {
			return $tag;
		}

		$full    = $m[0][0];
		$offset  = (int) $m[0][1];
		$replace = 'content="' . $escaped . '"';

		return substr($tag, 0, $offset) . $replace . substr($tag, $offset + strlen($full));
	}
}
