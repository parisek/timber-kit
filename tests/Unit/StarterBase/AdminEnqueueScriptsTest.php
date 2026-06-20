<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that admin_enqueue_scripts() enqueues the resizable-editor-sidebar
 * assets from the package (assets/js|css/) via packageUrl(), and that
 * $admin_resizable_sidebar = false suppresses the enqueue.
 */
class AdminEnqueueScriptsTest extends StarterBaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Common WP function stubs needed by admin_enqueue_scripts() and packageUrl().
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->alias( function ( string $path = '' ): string {
			return 'https://example.test/wp-content' . $path;
		} );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.test/wp-content/themes/test' );
	}

	/** Build a mock WP_Screen whose is_block_editor() returns the given value. */
	private function makeScreen( bool $is_block_editor ): object {
		return new class( $is_block_editor ) {
			public function __construct( private bool $blockEditor ) {}
			public function is_block_editor(): bool { return $this->blockEditor; }
		};
	}

	public function test_enqueues_script_and_style_from_package_on_block_editor_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( $this->makeScreen( true ) );
		// filemtime() runs natively — the real asset files exist under assets/ in the package.

		$enqueued_scripts = [];
		$enqueued_styles  = [];

		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src, $deps, $ver, $in_footer ) use ( &$enqueued_scripts ) {
				$enqueued_scripts[] = [ 'handle' => $handle, 'src' => $src ];
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src, $deps, $ver ) use ( &$enqueued_styles ) {
				$enqueued_styles[] = [ 'handle' => $handle, 'src' => $src ];
			}
		);

		$base = $this->createStarterBase( [ 'admin_resizable_sidebar' => true ] );
		$base->admin_enqueue_scripts();

		$this->assertCount( 1, $enqueued_scripts, 'Expected exactly one script enqueued' );
		$this->assertCount( 1, $enqueued_styles, 'Expected exactly one style enqueued' );

		$this->assertStringContainsString(
			'resizable-editor-sidebar',
			$enqueued_scripts[0]['handle'],
			'Script handle must contain resizable-editor-sidebar'
		);
		$this->assertStringContainsString(
			'/assets/js/gutenberg-resizable-sidebar.js',
			$enqueued_scripts[0]['src'],
			'Script src must point to the package assets/js/ path'
		);

		$this->assertStringContainsString(
			'resizable-editor-sidebar',
			$enqueued_styles[0]['handle'],
			'Style handle must contain resizable-editor-sidebar'
		);
		$this->assertStringContainsString(
			'/assets/css/gutenberg-resizable-sidebar.css',
			$enqueued_styles[0]['src'],
			'Style src must point to the package assets/css/ path'
		);
	}

	public function test_skips_enqueue_when_not_block_editor_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( $this->makeScreen( false ) );

		$enqueued = [];
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued ) { $enqueued[] = true; } );
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued ) { $enqueued[] = true; } );

		$base = $this->createStarterBase( [ 'admin_resizable_sidebar' => true ] );
		$base->admin_enqueue_scripts();

		$this->assertEmpty( $enqueued, 'Nothing should be enqueued outside the block editor' );
	}

	public function test_skips_enqueue_when_screen_is_null(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );

		$enqueued = [];
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued ) { $enqueued[] = true; } );
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued ) { $enqueued[] = true; } );

		$base = $this->createStarterBase( [ 'admin_resizable_sidebar' => true ] );
		$base->admin_enqueue_scripts();

		$this->assertEmpty( $enqueued, 'Nothing should be enqueued when get_current_screen() returns null' );
	}

	public function test_skips_enqueue_when_flag_is_false(): void {
		Functions\when( 'get_current_screen' )->justReturn( $this->makeScreen( true ) );

		$enqueued = [];
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued ) { $enqueued[] = true; } );
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued ) { $enqueued[] = true; } );

		$base = $this->createStarterBase( [ 'admin_resizable_sidebar' => false ] );
		$base->admin_enqueue_scripts();

		$this->assertEmpty( $enqueued, '$admin_resizable_sidebar = false must suppress the enqueue' );
	}
}
