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
		add_action('plugins_loaded', [$this->container->get(I18n::class), 'load']);

		$registry = $this->container->get(Settings\Registry::class);
		add_action('admin_init', [$registry, 'register']);

		$settings = $this->container->get(Admin\SettingsPage::class);
		add_action('admin_menu', [$settings, 'register']);

		$notices = $this->container->get(Admin\Notices::class);
		$notices->register();

		$assets = $this->container->get(Assets\AssetLoader::class);
		add_action('admin_enqueue_scripts', [$assets, 'enqueue_admin']);
		add_action('wp_enqueue_scripts', [$assets, 'enqueue_frontend']);

		$meta = $this->container->get(Upload\MetaHandler::class);
		add_action('init', [$meta, 'register']);

		$server_fallback = $this->container->get(Upload\ServerFallbackOptimizer::class);
		add_action('init', [$server_fallback, 'register']);

		add_action(
			'update_option_' . Settings\Registry::OPTION_NAME,
			static function (): void {
				delete_transient('menagerie_conflict_scan');
			}
		);
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
