<?php
/**
 * AJAX handlers for Tools → Image Converter.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Admin;

use Menagerie\Upload\ImageEncoder;
use Menagerie\Upload\LibraryConverter;

if (! defined('ABSPATH')) {
	exit;
}

final class ImageConverterAjax {
	public const NONCE_ACTION = 'menagerie_image_convert';

	public const BATCH_SIZE = 5;

	public function __construct(
		private ImageEncoder $encoder,
		private LibraryConverter $converter
	) {
	}

	public function register(): void {
		add_action('wp_ajax_menagerie_image_convert_start', [$this, 'ajax_start']);
		add_action('wp_ajax_menagerie_image_convert_batch', [$this, 'ajax_batch']);
	}

	public function ajax_start(): void {
		$this->guard();

		$target = isset($_POST['target']) ? sanitize_key((string) wp_unslash($_POST['target'])) : '';
		$mime   = $this->encoder->mime_for_target($target);
		if ($mime === null) {
			wp_send_json_error(
				[ 'message' => __('Invalid target format.', 'menagerie') ],
				400
			);
		}

		if (! $this->encoder->supports_output_mime($mime)) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: MIME type */
						__('This host cannot encode %s.', 'menagerie'),
						$mime
					),
				],
				400
			);
		}

		$total = $this->count_image_attachments();

		wp_send_json_success(
			[
				'total'     => $total,
				'batchSize' => self::BATCH_SIZE,
				'target'    => $target,
				'mime'      => $mime,
			]
		);
	}

	public function ajax_batch(): void {
		$this->guard();

		if (function_exists('wp_raise_memory_limit')) {
			wp_raise_memory_limit('image');
		}
		if (function_exists('set_time_limit')) {
			@set_time_limit(120);
		}

		$target = isset($_POST['target']) ? sanitize_key((string) wp_unslash($_POST['target'])) : '';
		$mime   = $this->encoder->mime_for_target($target);
		if ($mime === null) {
			wp_send_json_error(
				[ 'message' => __('Invalid target format.', 'menagerie') ],
				400
			);
		}

		if (! $this->encoder->supports_output_mime($mime)) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: MIME type */
						__('This host cannot encode %s.', 'menagerie'),
						$mime
					),
				],
				400
			);
		}

		$offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
		$ids    = $this->query_image_attachment_ids($offset, self::BATCH_SIZE);

		$converted = 0;
		$skipped   = 0;
		$failed    = [];

		foreach ($ids as $id) {
			try {
				$result = $this->converter->convert_attachment($id, $mime);
			} catch (\Throwable $e) {
				$result = [
					'status'  => 'failed',
					'message' => __('Unexpected error while converting this image.', 'menagerie'),
				];
			}
			$status = $result['status'] ?? 'failed';
			if ($status === 'converted') {
				++$converted;
			} elseif ($status === 'skipped') {
				++$skipped;
			} else {
				$title = get_the_title($id);
				if (! is_string($title) || $title === '') {
					$title = sprintf(
						/* translators: %d: attachment ID */
						__('Attachment #%d', 'menagerie'),
						$id
					);
				}
				$failed[] = [
					'id'     => $id,
					'title'  => $title,
					'reason' => isset($result['message']) ? (string) $result['message'] : __('Unknown error.', 'menagerie'),
				];
			}
		}

		$processed   = count($ids);
		$next_offset = $offset + $processed;
		$done        = $processed < self::BATCH_SIZE;

		wp_send_json_success(
			[
				'processed'  => $processed,
				'converted'  => $converted,
				'skipped'    => $skipped,
				'failed'     => $failed,
				'done'       => $done,
				'nextOffset' => $next_offset,
			]
		);
	}

	private function guard(): void {
		if (! current_user_can(ImageConverterPage::CAPABILITY)) {
			wp_send_json_error(
				[ 'message' => __( 'You are not allowed to convert images.', 'menagerie' ) ],
				403
			);
		}
		if (! check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
			wp_send_json_error(
				[ 'message' => __( 'Security check failed. Reload the page and try again.', 'menagerie' ) ],
				403
			);
		}
	}

	private function count_image_attachments(): int {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => [ 'inherit', 'private' ],
				'post_mime_type'         => 'image',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return (int) $query->found_posts;
	}

	/**
	 * @return list<int>
	 */
	private function query_image_attachment_ids(int $offset, int $limit): array {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => [ 'inherit', 'private' ],
				'post_mime_type'         => 'image',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$ids = [];
		foreach ($query->posts as $id) {
			$ids[] = (int) $id;
		}

		return $ids;
	}
}
