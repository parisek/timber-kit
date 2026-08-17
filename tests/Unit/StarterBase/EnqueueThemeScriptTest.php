<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that enqueueThemeScript() carries the resolved filename all the way
 * into `wp_enqueue_script_module()` / `wp_enqueue_script()`.
 *
 * ThemeScriptFileTest covers the resolver in isolation. This covers the joint
 * between it and the enqueue, which the resolver's own tests cannot see: the
 * filename is concatenated twice — once onto a URL and once onto a filesystem
 * path for the version stamp — and interpolating the wrong one into either
 * would leave every unit test green while the site enqueues a 404.
 */
class EnqueueThemeScriptTest extends StarterBaseTestCase {

	private string $themeDir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->themeDir = sys_get_temp_dir() . '/timber-kit-theme-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->themeDir . '/static/dist/js/.vite', 0777, true );

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'get_template_directory' )->justReturn( $this->themeDir );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.test/wp-content/themes/test' );
	}

	protected function tearDown(): void {
		$js = $this->themeDir . '/static/dist/js';
		foreach ( glob( $js . '/{,.vite/}*', GLOB_BRACE ) ?: [] as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( [ $js . '/.vite', $js, $this->themeDir . '/static/dist', $this->themeDir . '/static', $this->themeDir ] as $dir ) {
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		parent::tearDown();
	}

	/** Capture the one enqueue call the method makes. */
	private function enqueue( string $strategy = 'module' ): array {
		$calls = [];

		Functions\when( 'wp_enqueue_script_module' )->alias(
			function ( $handle, $src, $deps, $ver ) use ( &$calls ) {
				$calls[] = [ 'fn' => 'module', 'src' => $src, 'ver' => $ver ];
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src, $deps, $ver, $args ) use ( &$calls ) {
				$calls[] = [ 'fn' => 'classic', 'src' => $src, 'ver' => $ver ];
			}
		);

		$base = $this->createStarterBase( [ 'theme_script_strategy' => $strategy ] );
		( new \ReflectionMethod( $base, 'enqueueThemeScript' ) )->invoke( $base );

		$this->assertCount( 1, $calls, 'Expected exactly one enqueue call' );

		return $calls[0];
	}

	private function writeEntry( string $file ): void {
		$js = $this->themeDir . '/static/dist/js';
		file_put_contents( $js . '/' . $file, '/* built */' );
		file_put_contents(
			$js . '/.vite/manifest.json',
			json_encode( [ 'src/js/script.js' => [ 'file' => $file, 'isEntry' => true ] ], JSON_UNESCAPED_SLASHES )
		);
	}

	public function test_enqueues_the_hashed_filename_from_the_manifest(): void {
		$this->writeEntry( 'script.B7fm2cuz.min.js' );

		$call = $this->enqueue();

		$this->assertSame(
			'https://example.test/wp-content/themes/test/static/dist/js/script.B7fm2cuz.min.js',
			$call['src']
		);
	}

	/**
	 * The version stamp is `filemtime()` of the file being enqueued. Reading it
	 * from the unhashed path would silently produce `null` — a URL with no
	 * cache buster — and the src assertion above cannot see that.
	 */
	public function test_versions_the_same_file_it_enqueues(): void {
		$this->writeEntry( 'script.B7fm2cuz.min.js' );

		$call = $this->enqueue();

		$this->assertSame(
			(string) filemtime( $this->themeDir . '/static/dist/js/script.B7fm2cuz.min.js' ),
			$call['ver'],
			'The version must come from the hashed file, not from the fallback path.'
		);
	}

	public function test_enqueues_the_unhashed_name_when_no_manifest_exists(): void {
		file_put_contents( $this->themeDir . '/static/dist/js/script.js', '/* built */' );

		$call = $this->enqueue();

		$this->assertStringEndsWith( '/static/dist/js/script.js', $call['src'] );
	}

	public function test_the_classic_strategy_carries_the_same_filename(): void {
		$this->writeEntry( 'script.B7fm2cuz.min.js' );

		$call = $this->enqueue( 'defer' );

		$this->assertSame( 'classic', $call['fn'] );
		$this->assertStringEndsWith( '/script.B7fm2cuz.min.js', $call['src'] );
	}
}
