<?php
/**
 * Main plugin bootstrap: registers hooks.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie;

if (! defined('ABSPATH')) {
	exit;
}

final class Plugin {
	public function __construct(
		private Container $container
	) {
	}

	public function register(): void {
		// init (not plugins_loaded): avoids early translation JIT warnings on WP 6.7+.
		add_action('init', [$this->container->get(I18n::class), 'load'], 0);

		$registry = $this->container->get(Settings\Registry::class);
		add_action('admin_init', [$registry, 'register']);

		$settings = $this->container->get(Admin\SettingsPage::class);
		add_action('admin_menu', [$settings, 'register']);

		$converter = $this->container->get(Admin\ImageConverterPage::class);
		add_action('admin_menu', [$converter, 'register']);

		$converter_ajax = $this->container->get(Admin\ImageConverterAjax::class);
		$converter_ajax->register();

		$notices = $this->container->get(Admin\Notices::class);
		$notices->register();

		$assets = $this->container->get(Assets\AssetLoader::class);
		add_action('admin_enqueue_scripts', [$assets, 'enqueue_admin']);
		add_action('wp_enqueue_scripts', [$assets, 'enqueue_frontend']);

		$meta = $this->container->get(Upload\MetaHandler::class);
		add_action('init', [$meta, 'register']);

		$jpeg_companion = $this->container->get(Upload\JpegCompanion::class);
		add_action('init', [$jpeg_companion, 'register']);

		$server_fallback = $this->container->get(Upload\ServerFallbackOptimizer::class);
		add_action('init', [$server_fallback, 'register']);

		$picture = $this->container->get(Frontend\PictureFallback::class);
		$picture->register();

		$og = $this->container->get(Frontend\OpenGraph::class);
		$og->register();

		/*
		 * WordPress 7.1+ client-side media processing (wasm-vips) overlaps with Menagerie’s
		 * browser/server optimization. Disable core CSMP while Menagerie is responsible
		 * for uploads so files are not double-processed.
		 */
		add_filter('wp_client_side_media_processing_enabled', [$this, 'filter_client_side_media_processing']);

		add_action(
			'update_option_' . Settings\Registry::OPTION_NAME,
			static function (): void {
				delete_transient('menagerie_conflict_scan');
			}
		);
	}

	/**
	 * @param bool $enabled Whether core client-side media processing is enabled.
	 */
	public function filter_client_side_media_processing($enabled): bool {
		$s = $this->container->get(Settings\Registry::class)->get();
		$menagerie_active = ! empty($s['enabled']) || ! empty($s['server_side_only']);
		$processes_somewhere = ! empty($s['process_admin']) || ! empty($s['process_frontend']);

		if ($menagerie_active && $processes_somewhere) {
			return false;
		}

		return (bool) $enabled;
	}

	public static function activate(): void {
		$registry = new Settings\Registry();
		if (get_option($registry->get_option_name(), null) === null) {
			add_option($registry->get_option_name(), $registry->get_defaults());
		}
		delete_transient('menagerie_conflict_scan');
	}

	public static function deactivate(): void {
		delete_transient('menagerie_conflict_scan');
	}
}
