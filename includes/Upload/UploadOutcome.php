<?php
/**
 * Detect upload format outcomes, AVIF→WebP rescue, and expose payload for toasts.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class UploadOutcome {
	public const OUTCOME_CONVERTED_AVIF = 'converted_avif';

	public const OUTCOME_FALLBACK_WEBP = 'fallback_webp';

	public const OUTCOME_CONVERTED_WEBP = 'converted_webp';

	public const OUTCOME_FAILED_KEEP = 'failed_keep_original';

	public const OUTCOME_COMPANION_ONLY = 'companion_only';

	public const OUTCOME_NONE = 'none';

	private static bool $recursing = false;

	/** @var array<int, true> */
	private array $handled_this_request = [];

	/** @var array<int, true> Outcomes recorded this request that should trigger a toast. */
	private array $notify_this_request = [];

	public function __construct(
		private Registry $registry,
		private ImageEncoder $encoder,
		private JpegCompanion $companion,
		private LibraryConverter $converter
	) {
	}

	public static function is_recursing(): bool {
		return self::$recursing;
	}

	public function register(): void {
		// After JpegCompanion (30): rescue + record at 40.
		add_filter('wp_generate_attachment_metadata', [$this, 'filter_process_outcome'], 40, 3);
		add_action('rest_after_insert_attachment', [$this, 'on_rest_after_insert'], 40, 3);
		add_filter('rest_prepare_attachment', [$this, 'filter_rest_prepare_attachment'], 10, 3);
		add_filter('wp_prepare_attachment_for_js', [$this, 'filter_prepare_attachment_for_js'], 10, 3);
	}

	/**
	 * @param array<string, mixed> $metadata Metadata.
	 * @param int                  $attachment_id ID.
	 * @param string               $context Context ('create'|'update'|…).
	 * @return array<string, mixed>
	 */
	public function filter_process_outcome($metadata, $attachment_id, $context = '') {
		if (! is_array($metadata)) {
			return $metadata;
		}
		if (LibraryConverter::is_recursing() || self::$recursing) {
			return $metadata;
		}
		/*
		 * Rescue only on create, or when this request already started an outcome pass
		 * (WP 7.1 create→update may land the modern file on the second metadata pass).
		 * Plain “update” (Regenerate Thumbnails, etc.) must not rewrite old library JPEGs.
		 */
		$allow_rescue = ($context === 'create')
			|| isset($this->handled_this_request[ (int) $attachment_id ]);

		$rescued = $this->process((int) $attachment_id, $metadata, $allow_rescue);
		/*
		 * Rescue regenerates and persists new metadata inside convert_attachment().
		 * Returning the pre-rescue $metadata here would overwrite WebP sizes with the
		 * stale JPEG-era array from the outer wp_generate_attachment_metadata call.
		 */
		if ($rescued) {
			$fresh = wp_get_attachment_metadata((int) $attachment_id);
			if (is_array($fresh) && $fresh !== []) {
				return $fresh;
			}
		}
		return $metadata;
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
		if (LibraryConverter::is_recursing() || self::$recursing) {
			return;
		}
		$this->process((int) $attachment->ID, null, true);
	}

	/**
	 * @param array<string, mixed>|null $metadata Live metadata when available.
	 * @param bool                      $allow_rescue Whether server-side format rescue is allowed.
	 * @return bool True when a server-side format rescue rewrote the attachment file/meta.
	 */
	public function process(int $attachment_id, ?array $metadata = null, bool $allow_rescue = true): bool {
		if ($attachment_id <= 0) {
			return false;
		}
		if (! wp_attachment_is_image($attachment_id)) {
			return false;
		}

		$existing_outcome = get_post_meta($attachment_id, MetaKeys::UPLOAD_OUTCOME, true);
		if (! is_string($existing_outcome)) {
			$existing_outcome = '';
		}

		/*
		 * Preserve terminal upload outcomes across later metadata passes so toasts
		 * and history are not rewritten to companion_only / none.
		 */
		if ($this->is_terminal_outcome($existing_outcome)
			&& ! isset($this->handled_this_request[ $attachment_id ])
		) {
			if ($this->companion->attachment_is_modern_image($attachment_id)) {
				$this->companion->maybe_ensure_missing($attachment_id, $metadata);
			}
			$this->handled_this_request[ $attachment_id ] = true;
			return false;
		}

		/*
		 * Same-request re-entry: only re-run to upgrade a failed/empty pass
		 * (WP 7.1 create→update may land the modern file on the second metadata pass).
		 */
		if (isset($this->handled_this_request[ $attachment_id ])) {
			$can_upgrade = $existing_outcome === self::OUTCOME_FAILED_KEEP
				|| $existing_outcome === self::OUTCOME_NONE
				|| $existing_outcome === '';
			if (! $can_upgrade) {
				return false;
			}
		}

		/*
		 * Regenerate Thumbnails / plain metadata “update”: keep companions fresh but
		 * do not rewrite upload outcomes or fire toasts.
		 */
		if (! $allow_rescue) {
			if ($this->companion->attachment_is_modern_image($attachment_id)) {
				$this->companion->maybe_ensure_missing($attachment_id, $metadata);
			}
			$this->handled_this_request[ $attachment_id ] = true;
			return false;
		}

		$settings = $this->registry->get();
		$mode     = (string) ($settings['output_format'] ?? 'keep');
		$mime     = $this->companion->resolve_attachment_mime($attachment_id);
		$rescued  = false;

		if ($mime === 'image/gif' || $mime === 'image/svg+xml') {
			$this->store_outcome($attachment_id, self::OUTCOME_NONE, false);
			$this->handled_this_request[ $attachment_id ] = true;
			return false;
		}

		$outcome = self::OUTCOME_NONE;

		if ($mode === 'avif') {
			if ($mime === 'image/avif') {
				$outcome = self::OUTCOME_CONVERTED_AVIF;
			} elseif (in_array($mime, [ 'image/jpeg', 'image/png' ], true)) {
				if ($allow_rescue) {
					if ($this->encoder->supports_output_mime('image/avif')
						&& $this->try_rescue($attachment_id, 'image/avif', $metadata)
					) {
						$outcome  = self::OUTCOME_CONVERTED_AVIF;
						$metadata = null;
						$rescued  = true;
					} elseif ($this->encoder->supports_output_mime('image/webp')
						&& $this->try_rescue($attachment_id, 'image/webp', $metadata)
					) {
						$outcome  = self::OUTCOME_FALLBACK_WEBP;
						$metadata = null;
						$rescued  = true;
					} else {
						$outcome = self::OUTCOME_FAILED_KEEP;
					}
				}
			} elseif ($mime === 'image/webp') {
				// Already WebP (direct upload or prior conversion); do not claim AVIF→WebP fallback.
				$outcome = self::OUTCOME_NONE;
			} elseif ($allow_rescue) {
				$outcome = self::OUTCOME_FAILED_KEEP;
			}
		} elseif ($mode === 'webp') {
			if ($mime === 'image/webp') {
				$outcome = self::OUTCOME_CONVERTED_WEBP;
			} elseif (in_array($mime, [ 'image/jpeg', 'image/png' ], true)) {
				if ($allow_rescue) {
					if ($this->encoder->supports_output_mime('image/webp')
						&& $this->try_rescue($attachment_id, 'image/webp', $metadata)
					) {
						$outcome  = self::OUTCOME_CONVERTED_WEBP;
						$metadata = null;
						$rescued  = true;
					} else {
						$outcome = self::OUTCOME_FAILED_KEEP;
					}
				}
			} elseif ($mime === 'image/avif') {
				// Already modern relative to WebP target; companions may still apply.
				$outcome = self::OUTCOME_NONE;
			} elseif ($allow_rescue) {
				$outcome = self::OUTCOME_FAILED_KEEP;
			}
		} else {
			if ($this->companion->attachment_is_modern_image($attachment_id)
				&& $this->companion->get_path($attachment_id) !== null
			) {
				$outcome = self::OUTCOME_COMPANION_ONLY;
			}
		}

		if ($this->companion->attachment_is_modern_image($attachment_id)) {
			$this->companion->maybe_ensure_missing($attachment_id, $metadata);
			// e.g. AVIF mode + direct WebP upload, or keep mode + modern file.
			if ($outcome === self::OUTCOME_NONE
				&& $this->companion->get_path($attachment_id) !== null
			) {
				$outcome = self::OUTCOME_COMPANION_ONLY;
			}
		}

		$notify = $outcome !== self::OUTCOME_NONE;
		$this->store_outcome($attachment_id, $outcome, $notify);
		$this->handled_this_request[ $attachment_id ] = true;

		return $rescued;
	}

	private function is_terminal_outcome(string $outcome): bool {
		return in_array(
			$outcome,
			[
				self::OUTCOME_CONVERTED_AVIF,
				self::OUTCOME_FALLBACK_WEBP,
				self::OUTCOME_CONVERTED_WEBP,
			],
			true
		);
	}

	/**
	 * @param array<string, mixed>|null $live_metadata In-flight metadata for orphan cleanup.
	 */
	private function try_rescue(int $attachment_id, string $target_mime, ?array $live_metadata): bool {
		self::$recursing = true;
		try {
			$result = $this->converter->convert_attachment(
				$attachment_id,
				$target_mime,
				true,
				$live_metadata
			);
		} finally {
			self::$recursing = false;
		}
		return ($result['status'] ?? '') === 'converted';
	}

	private function store_outcome(int $attachment_id, string $outcome, bool $notify): void {
		$allowed = [
			self::OUTCOME_CONVERTED_AVIF,
			self::OUTCOME_FALLBACK_WEBP,
			self::OUTCOME_CONVERTED_WEBP,
			self::OUTCOME_FAILED_KEEP,
			self::OUTCOME_COMPANION_ONLY,
			self::OUTCOME_NONE,
		];
		if (! in_array($outcome, $allowed, true)) {
			$outcome = self::OUTCOME_NONE;
			$notify  = false;
		}
		update_post_meta($attachment_id, MetaKeys::UPLOAD_OUTCOME, $outcome);
		if ($notify) {
			$this->notify_this_request[ $attachment_id ] = true;
		}
	}

	/**
	 * @return array{outcome: string, mime: string, companion: bool, notify: bool}
	 */
	public function payload_for_attachment(int $attachment_id): array {
		$outcome = get_post_meta($attachment_id, MetaKeys::UPLOAD_OUTCOME, true);
		if (! is_string($outcome) || $outcome === '') {
			$outcome = self::OUTCOME_NONE;
		}
		return [
			'outcome'   => $outcome,
			'mime'      => $this->companion->resolve_attachment_mime($attachment_id),
			'companion' => $this->companion->get_path($attachment_id) !== null,
			// Only true when process() ran this request — prevents Media Library GET spam.
			'notify'    => isset($this->notify_this_request[ $attachment_id ]),
		];
	}

	/**
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post Post.
	 * @param \WP_REST_Request  $request Request.
	 * @return \WP_REST_Response
	 */
	public function filter_rest_prepare_attachment($response, $post, $request) {
		if (! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post) {
			return $response;
		}
		$data = $response->get_data();
		if (! is_array($data)) {
			return $response;
		}
		$data['menagerie'] = $this->payload_for_attachment((int) $post->ID);
		$response->set_data($data);
		return $response;
	}

	/**
	 * @param array<string, mixed> $response Response.
	 * @param \WP_Post             $attachment Attachment.
	 * @param array<string, mixed> $meta Meta.
	 * @return array<string, mixed>
	 */
	public function filter_prepare_attachment_for_js($response, $attachment, $meta) {
		unset($meta);
		if (! is_array($response) || ! $attachment instanceof \WP_Post) {
			return $response;
		}
		$response['menagerie'] = $this->payload_for_attachment((int) $attachment->ID);
		return $response;
	}
}
