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

	public function test_aioseo_hook_is_registered(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		SocialImageBridge::register( 'aioseo' );

		$this->assertContains( 'aioseo_opengraph_default_image', $filters );
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
