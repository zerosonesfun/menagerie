<?php
/**
 * Lightweight service container.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie;

if (! defined('ABSPATH')) {
	exit;
}

final class Container {
	/**
	 * @var array<string, object>
	 */
	private array $instances = [];

	/**
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = [];

	public function __construct() {
		$this->factories = [
			Settings\Registry::class => static function (self $c): Settings\Registry {
				return new Settings\Registry();
			},
			Admin\ConflictDetector::class => static function (self $c): Admin\ConflictDetector {
				return new Admin\ConflictDetector();
			},
			Admin\Notices::class => static function (self $c): Admin\Notices {
				return new Admin\Notices(
					$c->get(Settings\Registry::class),
					$c->get(Admin\ConflictDetector::class)
				);
			},
			Admin\SettingsPage::class => static function (self $c): Admin\SettingsPage {
				return new Admin\SettingsPage(
					$c->get(Settings\Registry::class),
					$c->get(Admin\ConflictDetector::class),
					$c->get(Assets\AssetLoader::class)
				);
			},
			Assets\AssetLoader::class => static function (self $c): Assets\AssetLoader {
				return new Assets\AssetLoader(
					$c->get(Settings\Registry::class)
				);
			},
			Upload\MetaHandler::class => static function (self $c): Upload\MetaHandler {
				return new Upload\MetaHandler();
			},
			Upload\ServerFallbackOptimizer::class => static function (self $c): Upload\ServerFallbackOptimizer {
				return new Upload\ServerFallbackOptimizer(
					$c->get(Settings\Registry::class)
				);
			},
			I18n::class => static function (self $c): I18n {
				return new I18n();
			},
		];
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get(string $id): object {
		if (! isset($this->instances[$id])) {
			if (! isset($this->factories[$id])) {
				throw new \InvalidArgumentException('Unknown service: ' . $id);
			}
			$this->instances[$id] = ($this->factories[$id])($this);
		}

		/** @var T */
		return $this->instances[$id];
	}
}
