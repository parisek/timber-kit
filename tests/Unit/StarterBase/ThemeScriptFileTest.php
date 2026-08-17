<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that themeScriptFile() resolves the JS entry through the Vite
 * manifest, and falls back to the unhashed `script.js` in every case where the
 * manifest cannot be trusted.
 *
 * The fallback cases are the point of this test, not an afterthought: the
 * resolver ships BEFORE the build config that emits a manifest, so on every
 * consumer it starts out taking the fallback path. A regression there breaks
 * every theme at once, while a regression in the happy path breaks only themes
 * that have rebuilt.
 */
class ThemeScriptFileTest extends StarterBaseTestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => $value
		);
		$this->dir = sys_get_temp_dir() . '/timber-kit-manifest-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/.vite', 0777, true );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/{,.vite/}*', GLOB_BRACE ) ?: [] as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( [ $this->dir . '/.vite', $this->dir ] as $path ) {
			if ( is_dir( $path ) ) {
				rmdir( $path );
			}
		}
		parent::tearDown();
	}

	/** Call the protected resolver on an instance built by the shared test case. */
	private function resolve(): string {
		$base = $this->createStarterBase();

		return ( new \ReflectionMethod( $base, 'themeScriptFile' ) )
			->invoke( $base, $this->dir );
	}

	private function writeManifest( array $manifest ): void {
		file_put_contents(
			$this->dir . '/.vite/manifest.json',
			json_encode( $manifest, JSON_UNESCAPED_SLASHES )
		);
	}

	public function test_returns_the_hashed_entry_named_by_the_manifest(): void {
		touch( $this->dir . '/script.B7fm2cuz.min.js' );
		$this->writeManifest(
			[
				'_a11y.oC0Z8zE_.min.js' => [ 'file' => 'a11y.oC0Z8zE_.min.js' ],
				'src/js/script.js'      => [
					'file'    => 'script.B7fm2cuz.min.js',
					'isEntry' => true,
				],
			]
		);

		$this->assertSame( 'script.B7fm2cuz.min.js', $this->resolve() );
	}

	public function test_falls_back_when_no_manifest_exists(): void {
		$this->assertSame(
			'script.js',
			$this->resolve(),
			'A theme that has not rebuilt must keep working unchanged.'
		);
	}

	public function test_falls_back_when_the_manifest_is_not_valid_json(): void {
		file_put_contents( $this->dir . '/.vite/manifest.json', '{ this is not json' );

		$this->assertSame( 'script.js', $this->resolve() );
	}

	public function test_falls_back_when_no_record_is_an_entry(): void {
		touch( $this->dir . '/a11y.oC0Z8zE_.min.js' );
		$this->writeManifest( [ '_a11y.oC0Z8zE_.min.js' => [ 'file' => 'a11y.oC0Z8zE_.min.js' ] ] );

		$this->assertSame( 'script.js', $this->resolve() );
	}

	/**
	 * A manifest naming a file that is not on disk is worse than no manifest:
	 * enqueueing it would 404 and take the whole bundle down, so the resolver
	 * treats the record as untrustworthy rather than authoritative.
	 */
	public function test_falls_back_when_the_named_entry_file_is_missing(): void {
		$this->writeManifest(
			[ 'src/js/script.js' => [ 'file' => 'script.deleted.min.js', 'isEntry' => true ] ]
		);

		$this->assertSame( 'script.js', $this->resolve() );
	}

	public function test_returns_a_filename_never_a_path(): void {
		touch( $this->dir . '/script.B7fm2cuz.min.js' );
		$this->writeManifest(
			[ 'src/js/script.js' => [ 'file' => 'script.B7fm2cuz.min.js', 'isEntry' => true ] ]
		);

		$this->assertStringNotContainsString(
			'/',
			$this->resolve(),
			'The caller joins this onto both a filesystem path and a URL, so a path here would break one of them.'
		);
	}

	/**
	 * A regression guard, not a preference test.
	 *
	 * An earlier, scanning resolver selected whichever entry came first and
	 * would have enqueued this stylesheet as a script module. Under the keyed
	 * lookup order cannot matter — which is exactly why the case is worth
	 * pinning: it must stay uninteresting if the lookup is ever changed back.
	 */
	public function test_prefers_the_conventional_entry_over_another_entry_listed_first(): void {
		touch( $this->dir . '/style.h.css' );
		touch( $this->dir . '/script.B7fm2cuz.min.js' );
		$this->writeManifest(
			[
				'src/css/style.css' => [ 'file' => 'style.h.css', 'isEntry' => true ],
				'src/js/script.js'  => [ 'file' => 'script.B7fm2cuz.min.js', 'isEntry' => true ],
			]
		);

		$this->assertSame( 'script.B7fm2cuz.min.js', $this->resolve() );
	}

	/**
	 * The escape hatch for a consumer that renamed its Vite input.
	 *
	 * An earlier version answered this by taking the first usable `isEntry`
	 * record, which neither Sage nor Laravel does — both make the caller ask for
	 * a different key. `ENTRY_MANIFEST_KEY` is `protected` for exactly that.
	 */
	public function test_a_subclass_can_point_the_lookup_at_a_renamed_input(): void {
		touch( $this->dir . '/app.Cq31vv.min.js' );
		$this->writeManifest(
			[ 'src/js/app.js' => [ 'file' => 'app.Cq31vv.min.js', 'isEntry' => true ] ]
		);

		$base = new class extends \Parisek\TimberKit\StarterBase {
			protected const ENTRY_MANIFEST_KEY = 'src/js/app.js';
			public function __construct() {}
		};

		$this->assertSame(
			'app.Cq31vv.min.js',
			( new \ReflectionMethod( $base, 'themeScriptFile' ) )->invoke( $base, $this->dir )
		);
	}

	/** Without that override, an unconventional key is simply not this theme's entry. */
	public function test_an_unconventional_key_alone_does_not_resolve(): void {
		touch( $this->dir . '/app.Cq31vv.min.js' );
		$this->writeManifest(
			[ 'src/js/app.js' => [ 'file' => 'app.Cq31vv.min.js', 'isEntry' => true ] ]
		);

		$this->assertSame( 'script.js', $this->resolve() );
	}

	/**
	 * The key decides which RECORD is read, not what its `file` says.
	 *
	 * An earlier version of this test put the stylesheet under `src/css/style.css`
	 * and passed — but only because that key is not the one looked up, so it was
	 * measuring the key miss, not the suffix check. It kept passing with the
	 * suffix check deleted, which is how the guard came to be removed on a false
	 * premise. The manifest here uses the REAL key.
	 */
	public function test_rejects_a_stylesheet_named_under_the_script_key(): void {
		touch( $this->dir . '/style.h.css' );
		$this->writeManifest(
			[ 'src/js/script.js' => [ 'file' => 'style.h.css', 'isEntry' => true ] ]
		);

		$this->assertSame( 'script.js', $this->resolve() );
	}

	/** A key that is not the one looked up resolves to nothing — a separate case. */
	public function test_ignores_a_record_under_a_different_key(): void {
		touch( $this->dir . '/style.h.css' );
		$this->writeManifest(
			[ 'src/css/style.css' => [ 'file' => 'style.h.css', 'isEntry' => true ] ]
		);

		$this->assertSame( 'script.js', $this->resolve() );
	}

	/**
	 * `ENTRY_MANIFEST_KEY` is protected, so its value stops being this class's
	 * to guarantee. A non-string override made the array offset a fatal
	 * TypeError; it now degrades to the fallback.
	 */
	public function test_a_non_string_key_override_falls_back_instead_of_fatalling(): void {
		touch( $this->dir . '/script.B7fm2cuz.min.js' );
		$this->writeManifest(
			[ 'src/js/script.js' => [ 'file' => 'script.B7fm2cuz.min.js', 'isEntry' => true ] ]
		);

		$base = new class extends \Parisek\TimberKit\StarterBase {
			/** @var mixed Deliberately not a string — the shape a careless override takes. */
			protected const ENTRY_MANIFEST_KEY = [];
			public function __construct() {}
		};

		$this->assertSame(
			'script.js',
			( new \ReflectionMethod( $base, 'themeScriptFile' ) )->invoke( $base, $this->dir )
		);
	}

	/**
	 * `is_file()` resolves `..`, so without a bare-filename check the method
	 * hands back a path out of the directory it documents. Reproduced against
	 * the real method before this test existed.
	 */
	public function test_rejects_a_file_value_that_escapes_the_directory(): void {
		$outside = dirname( $this->dir ) . '/outside-' . basename( $this->dir );
		mkdir( $outside, 0777, true );
		touch( $outside . '/other.js' );
		$this->writeManifest(
			[
				'src/js/script.js' => [
					'file'    => '../outside-' . basename( $this->dir ) . '/other.js',
					'isEntry' => true,
				],
			]
		);

		$result = $this->resolve();

		unlink( $outside . '/other.js' );
		rmdir( $outside );

		$this->assertSame( 'script.js', $result );
	}

	/**
	 * Isolates the conventional-key preference from the `.js` check beside it.
	 *
	 * Two JS entries, the wanted one listed second — the shape a consumer reaches
	 * the day it adds a second Vite input (an admin or editor bundle). The
	 * keyed lookup makes order irrelevant by construction, so this asserts that
	 * property rather than a preference between candidates.
	 *
	 * It earned its place under the previous, scanning resolver, where it was
	 * the only test that could observe the key preference at all. Kept for the
	 * same reason as the case above: it fails loudly if scanning returns.
	 */
	public function test_prefers_the_conventional_entry_over_another_SCRIPT_listed_first(): void {
		touch( $this->dir . '/admin.Zz99xx.min.js' );
		touch( $this->dir . '/script.B7fm2cuz.min.js' );
		$this->writeManifest(
			[
				'src/js/admin.js'  => [ 'file' => 'admin.Zz99xx.min.js', 'isEntry' => true ],
				'src/js/script.js' => [ 'file' => 'script.B7fm2cuz.min.js', 'isEntry' => true ],
			]
		);

		$this->assertSame(
			'script.B7fm2cuz.min.js',
			$this->resolve(),
			'A second JS input must not displace the theme bundle by being listed first.'
		);
	}

	/**
	 * The filter is the surface a PLUGIN can reach; the const needs a subclass.
	 * That difference is the whole reason it exists, so it is asserted rather
	 * than assumed — an extension point nobody has watched work is a comment.
	 */
	public function test_a_filter_can_repoint_the_lookup(): void {
		touch( $this->dir . '/app.Cq31vv.min.js' );
		$this->writeManifest(
			[ 'src/js/app.js' => [ 'file' => 'app.Cq31vv.min.js', 'isEntry' => true ] ]
		);

		\Brain\Monkey\Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'timber_kit_theme_script_manifest_key' === $hook
				? 'src/js/app.js'
				: $value
		);

		$this->assertSame( 'app.Cq31vv.min.js', $this->resolve() );
	}

	/** A filter returning nonsense degrades, exactly as a bad const override does. */
	public function test_a_filter_returning_a_non_string_falls_back(): void {
		touch( $this->dir . '/script.B7fm2cuz.min.js' );
		$this->writeManifest(
			[ 'src/js/script.js' => [ 'file' => 'script.B7fm2cuz.min.js', 'isEntry' => true ] ]
		);

		\Brain\Monkey\Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'timber_kit_theme_script_manifest_key' === $hook ? [] : $value
		);

		$this->assertSame( 'script.js', $this->resolve() );
	}
}
