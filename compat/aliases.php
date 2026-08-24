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
 */

declare(strict_types=1);

if ( ! class_exists( 'Parisek\TimberKit\BreezeWarmupSitemap', false ) ) {
	class_alias(
		\Parisek\TimberKit\Breeze\WarmupSitemap::class,
		'Parisek\TimberKit\BreezeWarmupSitemap'
	);
}
