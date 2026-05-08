<?php
/**
 * Admin notices: conflicts and dismissible warnings.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Admin;

use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class Notices {
	private const USER_META_DISMISS = 'menagerie_dismiss_conflict_notice';

	private const AJAX_ACTION = 'menagerie_dismiss_conflict_notice';

	private const AJAX_NONCE = 'menagerie_dismiss_conflict_notice';

	public function __construct(
		private Registry $registry,
		private ConflictDetector $conflicts
	) {
	}

	public function register(): void {
		add_action('admin_notices', [$this, 'render']);
		add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_dismiss']);
	}

	public function render(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$settings = $this->registry->get();
		if (empty($settings['detect_conflicts'])) {
			return;
		}

		$list = $this->conflicts->get_conflicting_plugins();
		if ($list === []) {
			return;
		}

		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			return;
		}

		/*
		 * No-JS fallback: when the textual link is clicked the page reloads with the
		 * dismiss query args. Process before reading stored meta so the user is not shown
		 * the notice on the same load they dismissed it.
		 */
		if (isset($_GET['menagerie_dismiss']) && isset($_GET['menagerie_notice_nonce'])) {
			if (wp_verify_nonce(sanitize_text_field((string) wp_unslash($_GET['menagerie_notice_nonce'])), 'menagerie_dismiss_notice') === 1) {
				update_user_meta($user_id, self::USER_META_DISMISS, MENAGERIE_VERSION);
				return;
			}
		}

		$dismissed_version = (string) get_user_meta($user_id, self::USER_META_DISMISS, true);

		/*
		 * Migrate legacy binary marker so users who already dismissed pre-versioned-dismiss
		 * stay dismissed for the current plugin version.
		 */
		if ($dismissed_version === '1') {
			update_user_meta($user_id, self::USER_META_DISMISS, MENAGERIE_VERSION);
			$dismissed_version = MENAGERIE_VERSION;
		}

		if ($dismissed_version === MENAGERIE_VERSION) {
			return;
		}

		$names = array_map(
			static function (array $p): string {
				return (string) $p['name'];
			},
			$list
		);

		$plugin_list = implode(', ', $names);
		$dismiss_url = wp_nonce_url(
			add_query_arg('menagerie_dismiss', '1'),
			'menagerie_dismiss_notice',
			'menagerie_notice_nonce'
		);

		$ajax_nonce = wp_create_nonce(self::AJAX_NONCE);

		echo '<div class="notice notice-warning is-dismissible menagerie-admin-notice" data-menagerie-notice="conflict" data-menagerie-nonce="' . esc_attr($ajax_nonce) . '"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: comma-separated plugin names */
				__('Menagerie detected other image optimization plugins that may overlap with client-side optimization: %s.', 'menagerie'),
				$plugin_list
			)
		);
		echo ' ';
		echo esc_html(
			__('Running multiple optimizers can cause double compression. Consider disabling overlapping image optimization in those plugins or in Menagerie.', 'menagerie')
		);
		echo ' ';
		printf(
			'<a href="%s">%s</a>',
			esc_url(admin_url('options-general.php?page=menagerie')),
			esc_html__('Menagerie settings', 'menagerie')
		);
		echo '</p><p><a href="' . esc_url($dismiss_url) . '">' . esc_html__('Don\'t show this again', 'menagerie') . '</a></p></div>';

		$this->print_dismiss_script();
	}

	public function ajax_dismiss(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer(self::AJAX_NONCE, 'nonce');

		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			wp_send_json_error(['message' => 'no-user'], 400);
		}

		update_user_meta($user_id, self::USER_META_DISMISS, MENAGERIE_VERSION);
		wp_send_json_success();
	}

	/**
	 * Capture-phase delegated click on the WP-native dismiss button so the X persists like the link.
	 * No-op when ajaxurl is missing (e.g. front-end accidentally rendering an admin notice).
	 */
	private function print_dismiss_script(): void {
		$action = self::AJAX_ACTION;
		$script = <<<JS
(function () {
	if (window.menagerieConflictDismissBound) {
		return;
	}
	window.menagerieConflictDismissBound = true;
	document.addEventListener('click', function (ev) {
		var btn = ev.target;
		if (!btn || !btn.classList || !btn.classList.contains('notice-dismiss')) {
			return;
		}
		var notice = btn.closest && btn.closest('.menagerie-admin-notice[data-menagerie-notice="conflict"]');
		if (!notice) {
			return;
		}
		var nonce = notice.getAttribute('data-menagerie-nonce') || '';
		if (!nonce || typeof window.ajaxurl !== 'string') {
			return;
		}
		var body = new URLSearchParams();
		body.append('action', '{$action}');
		body.append('nonce', nonce);
		try {
			fetch(window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			});
		} catch (e) { /* ignore */ }
	}, true);
})();
JS;
		echo "<script>{$script}</script>";
	}
}
