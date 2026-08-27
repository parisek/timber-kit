<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use Parisek\TimberKit\Seo\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Everything that names an SEO plugin lives under `src/Seo/`.
 *
 * Same excision argument as `BreezeBoundaryTest`: a boundary nobody enforces
 * blurs within months, one convenient `wpseo_`-prefixed filter at a time, and
 * the directory stops meaning anything.
 *
 * Four exceptions, all older than this namespace and all deliberate:
 * - `SocialImageBridge` bridges AIOSEO's og:image and predates `src/Seo/`.
 *   Moving it would change the package's public surface for no behavioural gain.
 * - `Breeze\WarmupSitemap` picks a sitemap path per plugin. Its detection is
 *   redirected into `Seo\Plugin`, but the path table is warmup's own business.
 * - `Breeze\Scorer` and `Breeze\SourceNaming` only discuss the two plugins'
 *   sitemap *filename* shapes (`<type>-sitemap.xml`, index ordering) to score
 *   a URL's provenance during warm-up. Neither detects which plugin is
 *   active, registers a filter, or reads a plugin symbol — they predate this
 *   namespace and moving them would tangle sitemap scoring with SEO tag
 *   detection for no behavioural gain.
 */
class SeoBoundaryTest extends TestCase {

	/** @var array<int, string> Paths allowed to name an SEO plugin outside src/Seo/. */
	private const ALLOWED = array(
		'src/SocialImageBridge.php',
		'src/Breeze/WarmupSitemap.php',
		'src/Breeze/Scorer.php',
		'src/Breeze/SourceNaming.php',
	);

	/**
	 * @return array<int, string>
	 */
	private function phpFilesUnderSrc(): array {
		$root  = dirname( __DIR__, 3 ) . '/src';
		$files = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	public function test_only_the_seo_directory_names_an_seo_plugin(): void {
		$root      = dirname( __DIR__, 3 );
		$offenders = array();

		foreach ( $this->phpFilesUnderSrc() as $path ) {
			$relative = ltrim( str_replace( $root, '', $path ), '/' );

			if ( str_starts_with( $relative, 'src/Seo/' ) ) {
				continue;
			}
			if ( in_array( $relative, self::ALLOWED, true ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $path );
			if ( 1 === preg_match( '/wpseo|aioseo/i', $contents ) ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"An SEO plugin is named outside src/Seo/: " . implode( ', ', $offenders )
		);
	}

	/**
	 * Without this, the boundary test above passes by scanning nothing.
	 */
	public function test_the_seo_directory_is_not_empty(): void {
		$found = array_filter(
			$this->phpFilesUnderSrc(),
			static fn( string $path ): bool => str_contains( $path, '/src/Seo/' )
		);

		$this->assertNotEmpty( $found );
	}

	/**
	 * Every plugin `detect()` can name has an adapter, and every adapter can be
	 * registered. This is the mechanical half of README's capability table: a
	 * plugin listed there as supported but missing its class or its method
	 * fails here rather than at a customer's site.
	 *
	 * The `$keys` list below is a manual mirror of two other places —
	 * `Plugin::detect()`'s own recognised keys, and README's capability table
	 * row set. Its loud failure mode is exactly what this test is for: a key
	 * named here with no matching adapter class or `register()` method. Its
	 * silent failure mode is the opposite direction and this test cannot catch
	 * it — a third plugin added to `detect()` without a row added here, which
	 * would leave that plugin both untested and undocumented. Adding a plugin
	 * to `detect()` must add it here and to the README table in the same
	 * change.
	 */
	public function test_every_detectable_plugin_has_a_registrable_adapter(): void {
		$keys = array( 'yoast', 'aioseo' );

		foreach ( $keys as $key ) {
			$this->assertSame(
				$key,
				Plugin::detect( array( $key => true ) + array_fill_keys( $keys, false ) ),
				"detect() does not name '{$key}'"
			);

			$class = 'Parisek\\TimberKit\\Seo\\' . ucfirst( $key );

			$this->assertTrue( class_exists( $class ), "No adapter class for '{$key}'" );
			$this->assertTrue(
				( new \ReflectionClass( $class ) )->hasMethod( 'register' ),
				"{$class} declares no register()"
			);
		}
	}
}
