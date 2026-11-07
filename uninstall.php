<?php
/**
 * Uninstall: remove plugin options, transients, companion files, and Menagerie meta.
 *
 * @package Menagerie
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('menagerie_settings');
delete_transient('menagerie_conflict_scan');

global $wpdb;

$uploads = wp_get_upload_dir();
if (empty($uploads['error']) && ! empty($uploads['basedir'])) {
	$basedir = wp_normalize_path((string) $uploads['basedir']);
	$prefix  = trailingslashit($basedir);

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
			$rel = ltrim(str_replace('\\', '/', $rel), '/');
			if ($rel === ''
				|| str_contains($rel, '..')
				|| str_contains($rel, "\0")
				|| preg_match('#^[a-zA-Z]:#', $rel) === 1
			) {
				continue;
			}

			$path = wp_normalize_path(path_join($basedir, $rel));
			if ($path !== $basedir && ! str_starts_with($path, $prefix)) {
				continue;
			}

			if (! is_file($path)) {
				continue;
			}

			$real_path = realpath($path);
			$real_base = realpath($basedir);
			if ($real_path === false || $real_base === false) {
				continue;
			}
			$real_path   = wp_normalize_path($real_path);
			$real_base   = wp_normalize_path($real_base);
			$real_prefix = trailingslashit($real_base);
			if ($real_path !== $real_base && ! str_starts_with($real_path, $real_prefix)) {
				continue;
			}

			wp_delete_file($real_path);
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
