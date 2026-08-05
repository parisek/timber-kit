<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * Coverage for `StarterBase::typography_locale_resolver()` — the callable
 * `timber_twig()` passes as `TypographyExtension`'s second constructor
 * argument (parisek/twig-typography ^1.3) so `|typography` picks up
 * per-language settings (quote style, dash convention, single-character
 * word spacing, …) for the language of the content actually being
 * rendered, not just the house-global defaults.
 *
 * The resolver delegates to `Helpers::getLanguage()` — the kit's existing
 * single source of truth for language detection (WPML post/current-language
 * filters, `get_locale()` fallback) — rather than duplicating that probe
 * logic. Proven here by exercising the same WPML-filter/get_locale() paths
 * `Helpers::getLanguage()` already covers and asserting the resolver's
 * return value tracks them.
 */
class TypographyLocaleResolverTest extends StarterBaseTestCase {

	public function test_returns_a_callable(): void {
		$base = $this->createStarterBase();

		$this->assertIsCallable( $base->typography_locale_resolver() );
	}

	public function test_resolver_reflects_wpml_current_language(): void {
		Functions\when( 'apply_filters' )->alias( static function ( string $tag, $default ) {
			if ( $tag === 'wpml_current_language' ) {
				return 'cs';
			}
			return $default;
		} );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		$base = $this->createStarterBase();

		$resolver = $base->typography_locale_resolver();

		$this->assertSame( 'cs', $resolver() );
	}

	public function test_resolver_falls_back_to_get_locale_without_wpml(): void {
		Functions\when( 'apply_filters' )->alias( static fn ( string $tag, $default ) => $default );
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );
		$base = $this->createStarterBase();

		$resolver = $base->typography_locale_resolver();

		$this->assertSame( 'de', $resolver() );
	}

	/**
	 * Same request, two calls with a different WPML language in between —
	 * proves the resolver is re-evaluated per call (not memoized), matching
	 * `TypographyExtension`'s documented "invoked on every `|typography`
	 * call" contract so a language switch mid-render is honoured.
	 */
	public function test_resolver_is_not_memoized_across_calls(): void {
		$current_language = 'cs';
		Functions\when( 'apply_filters' )->alias( static function ( string $tag, $default ) use ( &$current_language ) {
			if ( $tag === 'wpml_current_language' ) {
				return $current_language;
			}
			return $default;
		} );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		$base     = $this->createStarterBase();
		$resolver = $base->typography_locale_resolver();

		$this->assertSame( 'cs', $resolver() );

		$current_language = 'de';
		$this->assertSame( 'de', $resolver() );
	}
}
