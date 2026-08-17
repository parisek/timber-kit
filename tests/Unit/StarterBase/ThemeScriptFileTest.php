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
	 * Nothing orders manifest records, and a consumer with a second Vite input
	 * gets several `isEntry` ones. A CSS entry listed first was selected by an
	 * earlier version and would have been enqueued as a script module.
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

	/** A consumer that renamed its input still resolves, by first usable record. */
	public function test_accepts_an_unconventional_key_when_it_is_the_only_usable_entry(): void {
		touch( $this->dir . '/app.Cq31vv.min.js' );
		$this->writeManifest(
			[ 'src/js/app.js' => [ 'file' => 'app.Cq31vv.min.js', 'isEntry' => true ] ]
		);

		$this->assertSame( 'app.Cq31vv.min.js', $this->resolve() );
	}

	/** A non-script entry is never enqueued as a script, whatever its key. */
	public function test_rejects_an_entry_that_is_not_javascript(): void {
		touch( $this->dir . '/style.h.css' );
		$this->writeManifest(
			[ 'src/css/style.css' => [ 'file' => 'style.h.css', 'isEntry' => true ] ]
		);

		$this->assertSame( 'script.js', $this->resolve() );
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
	 * The CSS case above passes with or without the preference, because the
	 * suffix check already rejects the stylesheet — so it cannot tell whether
	 * the preference works. TWO JavaScript entries can, and that is the shape a
	 * consumer reaches the day it adds a second Vite input (an admin bundle,
	 * an editor bundle). Order in the manifest is not ours to rely on.
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
}
