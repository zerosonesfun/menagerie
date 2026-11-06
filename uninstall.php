<?php
/**
 * Uninstall: remove plugin options, transients, and Menagerie meta.
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

$meta_keys = [
	'_menagerie_processed',
	'_menagerie_original_bytes',
	'_menagerie_optimized_bytes',
	'_menagerie_mime_out',
	'_menagerie_processed_at',
	'_menagerie_server_fallback',
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
