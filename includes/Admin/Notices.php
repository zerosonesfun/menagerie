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

	public function __construct(
		private Registry $registry,
		private ConflictDetector $conflicts
	) {
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
		if ($user_id && get_user_meta($user_id, self::USER_META_DISMISS, true) === '1') {
			return;
		}

		if (isset($_GET['menagerie_dismiss']) && isset($_GET['menagerie_notice_nonce'])) {
			if (wp_verify_nonce(sanitize_text_field((string) wp_unslash($_GET['menagerie_notice_nonce'])), 'menagerie_dismiss_notice') === 1 && $user_id) {
				update_user_meta($user_id, self::USER_META_DISMISS, '1');
				return;
			}
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

		echo '<div class="notice notice-warning is-dismissible menagerie-admin-notice" data-menagerie-notice="conflict"><p>';
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
		echo '</p><p><a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss this notice', 'menagerie') . '</a></p></div>';
	}
}
