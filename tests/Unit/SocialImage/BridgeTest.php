<?php

declare(strict_types=1);

namespace Tests\Unit\SocialImage;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\SocialImageBridge;
use PHPUnit\Framework\TestCase;

class BridgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_aioseo_hook_is_registered_with_both_arguments(): void {
		$registered = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$registered ) {
			$registered[ $hook ] = [ 'callback' => $callback, 'priority' => $priority, 'args' => $args ];
		} );

		SocialImageBridge::register( 'aioseo' );

		$this->assertArrayHasKey( 'aioseo_opengraph_default_image', $registered );
		$hook = $registered['aioseo_opengraph_default_image'];
		// The post arrives in the second argument, so one accepted arg would
		// hand the callback an image and no way to resolve anything for it.
		$this->assertSame( 2, $hook['args'] );
		$this->assertSame( 10, $hook['priority'] );
		$this->assertSame( [ SocialImageBridge::class, 'filterOpengraphImage' ], $hook['callback'] );
	}

	public function test_an_unknown_plugin_registers_nothing(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		SocialImageBridge::register( 'not-a-plugin' );

		$this->assertSame( [], $filters );
	}

	public function test_supported_plugins_are_discoverable(): void {
		// A caller configuring the flag should be able to see what it accepts
		// without reading the source.
		$this->assertContains( 'aioseo', SocialImageBridge::supported() );
	}

	public function test_a_non_post_argument_leaves_the_image_untouched(): void {
		$image = 'https://example.com/site-default.png';

		$this->assertSame( $image, SocialImageBridge::filterOpengraphImage( $image, [ null, 'article' ] ) );
	}

	public function test_a_missing_argument_list_leaves_the_image_untouched(): void {
		$image = 'https://example.com/site-default.png';

		$this->assertSame( $image, SocialImageBridge::filterOpengraphImage( $image, [] ) );
	}

	public function test_a_resolved_preview_is_handed_back_as_a_tuple(): void {
		// AIOSEO reads index 1 and 2 for og:image:width / og:image:height and
		// falls back to its globally configured dimensions when handed a bare
		// string — which would then describe a different image than it serves.
		$preview = [ 'src' => 'https://example.com/c/1200x630-center/hero.jpeg', 'width' => 1200, 'height' => 630 ];

		$result = SocialImageBridge::toTuple( $preview, 'https://example.com/site-default.png' );

		$this->assertSame( [ 'https://example.com/c/1200x630-center/hero.jpeg', 1200, 630 ], $result );
	}

	public function test_no_preview_keeps_what_the_plugin_resolved(): void {
		$fallback = 'https://example.com/site-default.png';

		$this->assertSame( $fallback, SocialImageBridge::toTuple( null, $fallback ) );
		$this->assertSame( $fallback, SocialImageBridge::toTuple( [ 'src' => '' ], $fallback ) );
	}

	public function test_an_unresolvable_post_leaves_the_image_untouched(): void {
		// Falling through to whatever the plugin resolved is the whole contract:
		// a working card beats a wrong one.
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) {
			unset( $filter, $args );
			return $default;
		} );

		$image = 'https://example.com/site-default.png';
		$post = new \WP_Post( [ 'ID' => 3, 'post_type' => 'page' ] );

		$this->assertSame( $image, SocialImageBridge::filterOpengraphImage( $image, [ $post, 'article' ] ) );
	}
}
