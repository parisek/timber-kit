<?php
/**
 * Class names this package has already published under a previous layout.
 *
 * Everything Breeze-specific moved under `Parisek\TimberKit\Breeze` so a
 * project that does not run the plugin can see in one directory what is dead
 * weight. `BreezeWarmupSitemap` had already shipped, so its old name keeps
 * resolving here rather than breaking on upgrade.
 *
 * The class is `final`, so a subclass shim is not an option — an alias is.
 *
 * `Parisek\Twig\CommonExtension` came from `parisek/twig-common`, which the
 * kit no longer requires. It now resolves to `Twig\UniqueIdExtension`, the one
 * feature of it that components use. A project that still installs
 * `twig-common` keeps the original class: the alias is set only when the name
 * does not resolve.
 */

declare(strict_types=1);

if ( ! class_exists( 'Parisek\TimberKit\BreezeWarmupSitemap', false ) ) {
	class_alias(
		\Parisek\TimberKit\Breeze\WarmupSitemap::class,
		'Parisek\TimberKit\BreezeWarmupSitemap'
	);
}

if ( ! class_exists( 'Parisek\Twig\CommonExtension' ) ) {
	class_alias(
		\Parisek\TimberKit\Twig\UniqueIdExtension::class,
		'Parisek\Twig\CommonExtension'
	);
}
