<?php
/**
 * Uninstall: remove plugin options only.
 *
 * @package Menagerie
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('menagerie_settings');
delete_transient('menagerie_conflict_scan');
