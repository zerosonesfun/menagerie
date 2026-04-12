<?php
/**
 * Stores optimization metadata on attachments when the client reports success.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

if (! defined('ABSPATH')) {
	exit;
}

final class MetaHandler {
	public function register(): void {
		add_action('add_attachment', [$this, 'on_add_attachment'], 10, 1);
		add_action('rest_after_insert_attachment', [$this, 'on_rest_after_insert'], 10, 3);
	}

	public function on_add_attachment(int $attachment_id): void {
		if (! current_user_can('upload_files')) {
			return;
		}

		$raw = null;
		if (! empty($_POST['menagerie_meta'])) {
			$raw = wp_unslash((string) $_POST['menagerie_meta']);
		}

		if ($raw === null || $raw === '') {
			return;
		}

		$this->apply_payload($attachment_id, $raw);
	}

	/**
	 * @param \WP_Post         $attachment Post object.
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

		$raw = $request->get_param('menagerie_meta');
		if (($raw === null || $raw === '') && ! empty($_POST['menagerie_meta'])) {
			$raw = wp_unslash((string) $_POST['menagerie_meta']);
		}
		if ($raw === null || $raw === '') {
			return;
		}

		$this->apply_payload((int) $attachment->ID, is_string($raw) ? $raw : wp_json_encode($raw));
	}

	private function apply_payload(int $attachment_id, string $raw): void {
		if (get_post_meta($attachment_id, MetaKeys::PROCESSED, true) === '1') {
			return;
		}

		$data = json_decode($raw, true);
		if (! is_array($data)) {
			return;
		}

		$nonce = isset($data['nonce']) ? (string) $data['nonce'] : '';
		if ($nonce === '' || wp_verify_nonce($nonce, 'menagerie_upload') !== 1) {
			return;
		}

		if (empty($data['processed'])) {
			return;
		}

		$original = isset($data['originalBytes']) ? (int) $data['originalBytes'] : 0;
		$optimized = isset($data['optimizedBytes']) ? (int) $data['optimizedBytes'] : 0;
		$mime      = isset($data['mimeOut']) ? sanitize_mime_type((string) $data['mimeOut']) : '';

		if ($mime === '') {
			$mime = 'application/octet-stream';
		}

		update_post_meta($attachment_id, MetaKeys::PROCESSED, '1');
		update_post_meta($attachment_id, MetaKeys::ORIGINAL_BYTES, $original);
		update_post_meta($attachment_id, MetaKeys::OPTIMIZED_BYTES, $optimized);
		update_post_meta($attachment_id, MetaKeys::MIME_OUT, $mime);
		update_post_meta($attachment_id, MetaKeys::PROCESSED_AT, time());
	}
}
