<?php
/**
 * Uninstall: remove plugin options, companion files, and Menagerie meta.
 *
 * @package Menagerie
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Clean options, companions, and meta for the current blog.
 */
$menagerie_uninstall_blog = static function (): void {
	delete_option('menagerie_settings');
	delete_transient('menagerie_conflict_scan');

	global $wpdb;

	$uploads = wp_get_upload_dir();
	if (empty($uploads['error']) && ! empty($uploads['basedir'])) {
		$basedir = wp_normalize_path((string) $uploads['basedir']);
		$prefix  = trailingslashit($basedir);

		$delete_relative = static function (string $rel) use ($basedir, $prefix): void {
			$rel = ltrim(str_replace('\\', '/', $rel), '/');
			if ($rel === ''
				|| str_contains($rel, '..')
				|| str_contains($rel, "\0")
				|| preg_match('#^[a-zA-Z]:#', $rel) === 1
			) {
				return;
			}

			$path = wp_normalize_path(path_join($basedir, $rel));
			if ($path !== $basedir && ! str_starts_with($path, $prefix)) {
				return;
			}

			if (! is_file($path)) {
				return;
			}

			$real_path = realpath($path);
			$real_base = realpath($basedir);
			if ($real_path === false || $real_base === false) {
				return;
			}
			$real_path   = wp_normalize_path($real_path);
			$real_base   = wp_normalize_path($real_base);
			$real_prefix = trailingslashit($real_base);
			if ($real_path !== $real_base && ! str_starts_with($real_path, $real_prefix)) {
				return;
			}

			wp_delete_file($real_path);
		};

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$companions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_menagerie_jpeg_relative'
			)
		);
		if (is_array($companions)) {
			foreach ($companions as $row) {
				$rel = isset($row->meta_value) ? (string) $row->meta_value : '';
				if ($rel !== '') {
					$delete_relative($rel);
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sized = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_menagerie_jpeg_sizes'
			)
		);
		if (is_array($sized)) {
			foreach ($sized as $row) {
				$raw = isset($row->meta_value) ? (string) $row->meta_value : '';
				if ($raw === '') {
					continue;
				}
				$map = json_decode($raw, true);
				if (! is_array($map)) {
					continue;
				}
				foreach ($map as $rel) {
					if (is_string($rel) && $rel !== '') {
						$delete_relative($rel);
					}
				}
			}
		}
	}

	$meta_keys = [
		'_menagerie_processed',
		'_menagerie_original_bytes',
		'_menagerie_optimized_bytes',
		'_menagerie_mime_out',
		'_menagerie_processed_at',
		'_menagerie_server_fallback',
		'_menagerie_jpeg_relative',
		'_menagerie_jpeg_width',
		'_menagerie_jpeg_height',
		'_menagerie_jpeg_sizes',
		'_menagerie_upload_outcome',
	];

	foreach ($meta_keys as $key) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete($wpdb->postmeta, [ 'meta_key' => $key ], [ '%s' ]);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete(
		$wpdb->usermeta,
		[ 'meta_key' => 'menagerie_dismiss_conflict_notice' ],
		[ '%s' ]
	);
};

if (is_multisite()) {
	$site_ids = get_sites([ 'fields' => 'ids', 'number' => 0 ]);
	if (is_array($site_ids)) {
		foreach ($site_ids as $site_id) {
			switch_to_blog((int) $site_id);
			$menagerie_uninstall_blog();
			restore_current_blog();
		}
	}
} else {
	$menagerie_uninstall_blog();
}
