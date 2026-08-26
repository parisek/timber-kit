<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze\Health;

use Parisek\TimberKit\Breeze\PriorityStore;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: `WarmupSitemap` is switched on but has no URLs to warm with.
 *
 * The module degrades silently by design — an empty sitemap result is a normal
 * return value, and the refresh job swallows throwables by contract so a
 * sitemap outage can never surface as a fatal in cron. Those are the right
 * choices for the purge path and the wrong ones for an administrator, who is
 * left with a warmup that preloads nothing and says nothing.
 *
 * Every cause looks identical from the outside — wrong provider, a sitemap
 * behind auth, a transport failure, a genuinely empty site. This check does not
 * try to tell them apart. It reports the one fact that is worth acting on: the
 * feature is on and the list is empty.
 */
final class WarmupSitemapResolved implements HealthCheck {

	public function id(): string {
		return 'warmup_sitemap_resolved';
	}

	public function label(): string {
		return __( 'Warmup list has URLs', 'timber-kit' );
	}

	public function category(): string {
		return 'caching';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		$stored = PriorityStore::read();

		// Never refreshed is not the same as refreshed-and-empty. The first
		// purge after activation schedules the refresh, so a fresh install
		// sits here for a few minutes through no fault of its own — calling
		// that a failure would train an administrator to ignore this check.
		if ( null === $stored ) {
			return Result::good( __( 'The warmup list has not been built yet.', 'timber-kit' ) );
		}

		$count = count( PriorityStore::readUrls() );

		if ( 0 === $count ) {
			return Result::critical(
				__( 'The sitemap warmup is on, but its URL list is empty.', 'timber-kit' ),
				__( 'The sitemap could not be read. Check that the site serves the sitemap of the SEO plugin it runs, and override the address with the timberkit_warmup_sitemap_url filter if it lives elsewhere.', 'timber-kit' )
			);
		}

		return Result::good(
			sprintf(
				/* translators: %d: number of URLs stored for warmup. */
				__( '%d URL(s) ready to warm.', 'timber-kit' ),
				$count
			)
		);
	}
}
