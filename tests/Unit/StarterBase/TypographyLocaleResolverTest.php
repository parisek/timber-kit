<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\Twig\TypographyExtension;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\StarterBaseTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

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
 *
 * `typography_locale_resolver()` is `protected` (an override point for a
 * subclassing theme's `Base`, not a Twig-callable — see the docblock at the
 * call site), so it's exercised here via reflection, mirroring the existing
 * `invoke_protected()` pattern used for `Breadcrumb`.
 */
class TypographyLocaleResolverTest extends StarterBaseTestCase {

	private function resolver( \Parisek\TimberKit\StarterBase $base ): callable {
		$reflection = new ReflectionMethod( $base, 'typography_locale_resolver' );

		return $reflection->invoke( $base );
	}

	public function test_returns_a_callable(): void {
		$base = $this->createStarterBase();

		$this->assertIsCallable( $this->resolver( $base ) );
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

		$resolver = $this->resolver( $base );

		$this->assertSame( 'cs', $resolver() );
	}

	/**
	 * `get_locale()`'s region subtag must survive the resolver unchanged —
	 * `Helpers::getLanguage()` no longer truncates to the bare language (see
	 * `GetLanguageTest::test_preserves_region_subtag_from_locale_fallback`),
	 * and this resolver must not reintroduce that truncation, since
	 * `de-CH`/`en-GB`-style entries in `parisek/twig-typography`'s language
	 * tables are only reachable with the region subtag intact.
	 */
	public function test_resolver_falls_back_to_get_locale_without_wpml(): void {
		Functions\when( 'apply_filters' )->alias( static fn ( string $tag, $default ) => $default );
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );
		$base = $this->createStarterBase();

		$resolver = $this->resolver( $base );

		$this->assertSame( 'de_de', $resolver() );
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
		$resolver = $this->resolver( $base );

		$this->assertSame( 'cs', $resolver() );

		$current_language = 'de';
		$this->assertSame( 'de', $resolver() );
	}

	/**
	 * End-to-end proof that `get_locale() === 'de_CH'` reaches the Swiss
	 * (`de-CH`) typographic table (Swiss guillemets `«…»`), not just the
	 * bare `de` one, is **not** part of this suite: `PHP_Typography`'s DOM
	 * walker passes nodes by reference internally, and Brain\Monkey's
	 * Patchwork interceptor rewrites that call in a way that turns every
	 * traversal step into a "must be passed by reference, value given"
	 * warning — for real prose this floods to thousands of warnings and
	 * the test never completes in practice (confirmed: killed after >90s).
	 * This is the same class of Patchwork/vendor-internals conflict noted
	 * in AGENTS.md for the Property suite (`ERIS_SEED` / bootstrap
	 * isolation) — that suite works around it by never having Brain\Monkey
	 * active; this one has no such option since it's testing the resolver
	 * that WPML/`get_locale()`-mocking requires. Verified manually instead,
	 * outside PHPUnit, against the real `parisek/twig-typography` install:
	 *
	 *     $ext = new TypographyExtension('', fn () => 'de_CH');
	 *     $ext->applyTypography('Er sagte "Hallo"');
	 *     // => 'Er sagte «Hallo»'   (Swiss guillemets)
	 *
	 *     $ext = new TypographyExtension('', fn () => 'de_DE');
	 *     $ext->applyTypography('Er sagte "Hallo"');
	 *     // => 'Er sagte „Hallo“'  (German low/high quotes, no guillemet)
	 *
	 * confirming the region subtag reaches `TypographyExtension`'s
	 * language-table lookup, not just the resolver's own return value.
	 */

	/**
	 * `timber_twig()` must actually pass the resolver through to
	 * `TypographyExtension` — not just have a resolver method that nothing
	 * calls. `TypographyExtension` exposes no public getter for its
	 * constructor arguments, so the private `localeResolver` property is
	 * read via reflection; this is the level at which this repo's harness
	 * (Brain\Monkey, no real WP boot) can observe extension wiring — there
	 * is no framework here for asserting against a rendered Twig template's
	 * final typographic output at the `timber_twig()` level itself.
	 */
	public function test_timber_twig_registers_typography_extension_with_the_resolver(): void {
		Functions\when( 'get_template_directory' )->justReturn( '/theme' );
		Functions\when( 'apply_filters' )->alias( static function ( string $tag, $default ) {
			if ( $tag === 'wpml_current_language' ) {
				return 'sk';
			}
			return $default;
		} );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'add_filter' )->justReturn( true );
		$base = $this->createStarterBase();

		$env = new Environment( new ArrayLoader() );
		$base->timber_twig( $env );

		$extension = $env->getExtension( TypographyExtension::class );
		$property  = new ReflectionProperty( TypographyExtension::class, 'localeResolver' );
		$registered_resolver = $property->getValue( $extension );

		$this->assertIsCallable( $registered_resolver, 'timber_twig() must register TypographyExtension with a non-null resolver.' );
		$this->assertSame( 'sk', $registered_resolver(), 'The registered resolver must be the kit\'s own (WPML-backed) resolver, not a stub.' );
	}
}
