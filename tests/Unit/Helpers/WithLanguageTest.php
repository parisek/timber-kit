<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Helpers;

/**
 * Switching WPML's language around one call, and putting it back.
 *
 * `get_permalink()` renders in the current language, not in the language of
 * the post it is handed, so the ID being right is not enough.
 */
final class WithLanguageTest extends TestCase {

	/** @var array<int, string> */
	private array $switches = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->switches = array();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value = null, ...$rest ) => 'wpml_current_language' === $hook ? 'en' : $value
		);
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ): void {
				if ( 'wpml_switch_language' === $hook ) {
					$this->switches[] = (string) ( $args[0] ?? '' );
				}
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_switches_there_and_back(): void {
		$seen = Helpers::withLanguage( 'it', static fn() => 'ran' );

		$this->assertSame( 'ran', $seen );
		$this->assertSame( array( 'it', 'en' ), $this->switches );
	}

	public function test_restores_the_language_when_the_callback_throws(): void {
		// The restore is in a finally because this runs alongside other work on
		// the same request. A throw that left the language switched would not
		// fail here — it would surface later, somewhere unrelated.
		$this->expectException( \RuntimeException::class );

		try {
			Helpers::withLanguage( 'it', static function (): void {
				throw new \RuntimeException( 'boom' );
			} );
		} finally {
			$this->assertSame( array( 'it', 'en' ), $this->switches );
		}
	}

	public function test_an_empty_language_does_not_switch(): void {
		$seen = Helpers::withLanguage( '', static fn() => 'ran' );

		$this->assertSame( 'ran', $seen );
		$this->assertSame( array(), $this->switches );
	}

	public function test_the_language_already_active_does_not_switch(): void {
		// Switching to the language already in force is two hooks that change
		// nothing, and each one costs WPML a cache rebuild.
		$seen = Helpers::withLanguage( 'en', static fn() => 'ran' );

		$this->assertSame( 'ran', $seen );
		$this->assertSame( array(), $this->switches );
	}
}
