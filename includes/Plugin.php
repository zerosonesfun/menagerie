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

		$output_format = $this->container->get(Upload\OutputFormat::class);
		$output_format->register();

		$jpeg_companion = $this->container->get(Upload\JpegCompanion::class);
		add_action('init', [$jpeg_companion, 'register']);

		$upload_outcome = $this->container->get(Upload\UploadOutcome::class);
		add_action('init', [$upload_outcome, 'register']);

		$picture = $this->container->get(Frontend\PictureFallback::class);
		$picture->register();

		$og = $this->container->get(Frontend\OpenGraph::class);
		$og->register();
	}

	public static function activate(): void {
		$registry = new Settings\Registry();
		if (get_option($registry->get_option_name(), null) === null) {
			add_option($registry->get_option_name(), $registry->get_defaults());
		}
	}

	public static function deactivate(): void {
		// No transients to clear in 2.0.
	}
}
