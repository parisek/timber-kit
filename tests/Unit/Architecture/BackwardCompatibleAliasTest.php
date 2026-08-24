<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The pre-move class name must keep resolving.
 *
 * `BreezeWarmupSitemap` shipped in v1.37.0 and v1.38.0, so a consumer may
 * reference it. The move to `Breeze\WarmupSitemap` keeps it alive through a
 * `class_alias` in `compat/aliases.php`, wired as a Composer `files` autoload
 * entry.
 *
 * Without this test the whole shim is invisible to the suite: every other test
 * uses the new name, so dropping the autoload entry, mistyping the alias, or
 * having it fail under an optimised autoloader would all leave the build
 * green. The one thing that must not break on upgrade is the one thing nothing
 * else exercises.
 */
class BackwardCompatibleAliasTest extends TestCase {

	/** @var string The name this package published before the move. */
	private const LEGACY = 'Parisek\TimberKit\BreezeWarmupSitemap';

	/** @var string Where that code lives now. */
	private const CURRENT = 'Parisek\TimberKit\Breeze\WarmupSitemap';

	public function test_the_published_class_name_still_resolves(): void {
		$this->assertTrue(
			class_exists( self::LEGACY ),
			self::LEGACY . ' shipped in v1.37.0 and must keep resolving after the move'
		);
	}

	public function test_the_legacy_name_points_at_the_moved_class(): void {
		// Resolving is not enough — it has to resolve to the right thing. An
		// alias pointing at some other class would satisfy class_exists().
		$this->assertTrue( is_a( self::LEGACY, self::CURRENT, true ) );
	}

	public function test_the_alias_is_wired_through_composer(): void {
		// The shim only loads because composer.json lists it under
		// autoload.files. Dropping that entry breaks the alias without
		// touching a single line of PHP, so pin it explicitly.
		$manifest = json_decode(
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/composer.json' ),
			true
		);

		$this->assertIsArray( $manifest );
		$this->assertContains(
			'compat/aliases.php',
			$manifest['autoload']['files'] ?? array(),
			'the alias shim must stay in composer.json autoload.files'
		);
	}
}
