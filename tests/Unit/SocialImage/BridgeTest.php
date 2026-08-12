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

	/**
	 * Stub AIOSEO's object graph down to the per-post meta.
	 *
	 * Needed in every test that reaches `imageType()`, because Brain\Monkey
	 * keeps function definitions for the whole run: once any earlier test
	 * defines `aioseo`, `function_exists()` is true everywhere after it.
	 *
	 * @param array<string, mixed> $props Meta properties, e.g. og_image_type.
	 */
	private function stubAioseoMeta( array $props = [] ): void {
		$meta = (object) $props;
		$metaData = new class( $meta ) {
			public function __construct( private object $meta ) {}
			public function getMetaData( $post = null ) {
				unset( $post );
				return $this->meta;
			}
		};
		$aioseo = (object) [ 'meta' => (object) [ 'metaData' => $metaData ] ];

		Functions\when( 'aioseo' )->justReturn( $aioseo );
	}

	public function test_aioseo_hook_is_registered_with_both_arguments(): void {
		$registered = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$registered ) {
			$registered[ $hook ] = [ 'callback' => $callback, 'priority' => $priority, 'args' => $args ];
		} );

		SocialImageBridge::register( 'aioseo' );

		$this->assertArrayHasKey( 'aioseo_opengraph_default_image', $registered );
		// Twitter resolves separately, so one hook is half the feature.
		$this->assertArrayHasKey( 'aioseo_twitter_tags', $registered );
		$this->assertSame( [ SocialImageBridge::class, 'filterTwitterTags' ], $registered['aioseo_twitter_tags']['callback'] );
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

	public function test_an_explicit_plugin_key_resolves_to_itself(): void {
		$this->assertSame( 'aioseo', SocialImageBridge::resolve( 'aioseo' ) );
	}

	public function test_an_unsupported_plugin_key_resolves_to_nothing(): void {
		$this->assertNull( SocialImageBridge::resolve( 'not-a-plugin' ) );
	}

	public function test_the_flag_off_resolves_to_nothing(): void {
		$this->assertNull( SocialImageBridge::resolve( false ) );
		$this->assertNull( SocialImageBridge::resolve( '' ) );
	}

	public function test_true_asks_for_detection(): void {
		// The site runs one SEO plugin, so naming it is configuration the
		// package can work out for itself.
		Functions\when( 'aioseo' )->justReturn( true );

		$this->assertSame( 'aioseo', SocialImageBridge::resolve( true ) );
	}

	public function test_an_explicit_key_wins_over_detection(): void {
		Functions\when( 'aioseo' )->justReturn( true );

		$this->assertSame( 'aioseo', SocialImageBridge::resolve( 'aioseo' ) );
	}

	public function test_an_explicit_per_post_choice_is_left_alone(): void {
		// The filter is named `default_image` but fires last, so it also sees
		// the image an editor picked by hand in the plugin's own panel.
		// Overwriting that is the plugin equivalent of ignoring the editor.
		$this->assertTrue( SocialImageBridge::defersToEditor( 'custom_image' ) );
		$this->assertTrue( SocialImageBridge::defersToEditor( 'featured' ) );
	}

	public function test_no_per_post_choice_leaves_the_field_free(): void {
		$this->assertFalse( SocialImageBridge::defersToEditor( 'default' ) );
		$this->assertFalse( SocialImageBridge::defersToEditor( '' ) );
		$this->assertFalse( SocialImageBridge::defersToEditor( null ) );
	}

	public function test_twitter_image_is_replaced_when_a_preview_resolves(): void {
		$meta = [ 'twitter:card' => 'summary_large_image', 'twitter:image' => 'https://example.com/site-default.png' ];
		$preview = [ 'src' => 'https://example.com/c/1200x630-center/hero.jpeg', 'width' => 1200, 'height' => 630 ];

		$result = SocialImageBridge::withTwitterImage( $meta, $preview );

		$this->assertSame( 'https://example.com/c/1200x630-center/hero.jpeg', $result['twitter:image'] );
		$this->assertSame( 'summary_large_image', $result['twitter:card'] );
	}

	public function test_twitter_meta_is_untouched_without_a_preview(): void {
		$meta = [ 'twitter:image' => 'https://example.com/site-default.png' ];

		$this->assertSame( $meta, SocialImageBridge::withTwitterImage( $meta, null ) );
	}

	public function test_twitter_image_is_a_bare_url_not_a_tuple(): void {
		// twitter:image is a URL string; AIOSEO reads og:image's width and
		// height from separate keys, Twitter's has no such pair.
		$preview = [ 'src' => 'https://example.com/hero.jpeg', 'width' => 1200, 'height' => 630 ];

		$result = SocialImageBridge::withTwitterImage( [], $preview );

		$this->assertIsString( $result['twitter:image'] );
	}

	public function test_a_post_with_an_editor_chosen_image_is_skipped_entirely(): void {
		$this->stubAioseoMeta( [ 'og_image_type' => 'custom_image' ] );

		$image = 'https://example.com/what-the-editor-picked.jpg';
		$post = new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] );

		// No field map is wired, so reaching resolution at all would fail the
		// test with a missing-mock error rather than silently passing.
		$this->assertSame( $image, SocialImageBridge::filterOpengraphImage( $image, [ $post, 'article' ] ) );
	}

	public function test_twitter_defers_when_the_card_reuses_the_open_graph_image(): void {
		// "Use Data from Facebook Tab" makes AIOSEO return the OG image for
		// Twitter, so the tag already carries what the og:image filter decided,
		// deferral included. Replacing it here would run that decision again
		// without the deferral and undo it. The per-post value defaults from the
		// global setting, so on a site with it on this is every post.
		$this->stubAioseoMeta( [ 'twitter_use_og' => true, 'og_image_type' => 'custom_image', 'twitter_image_type' => 'default' ] );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] ) );

		$meta = [ 'twitter:image' => 'https://example.com/what-the-editor-picked.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_twitter_defers_to_an_editor_chosen_twitter_image(): void {
		$this->stubAioseoMeta( [ 'twitter_use_og' => false, 'twitter_image_type' => 'custom_image' ] );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] ) );

		$meta = [ 'twitter:image' => 'https://example.com/what-the-editor-picked.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_twitter_tags_are_untouched_outside_a_singular_view(): void {
		Functions\when( 'is_singular' )->justReturn( false );

		$meta = [ 'twitter:image' => 'https://example.com/site-default.png' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_unreadable_plugin_metadata_means_defer(): void {
		// The contract is never to override an editor's choice, and an
		// unreadable state is not evidence that they made none.
		Functions\when( 'aioseo' )->justReturn( (object) [] );

		$image = 'https://example.com/what-the-plugin-resolved.jpg';
		$post = new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] );

		$this->assertSame( $image, SocialImageBridge::filterOpengraphImage( $image, [ $post, 'article' ] ) );
	}

	public function test_unreadable_plugin_metadata_means_defer_on_twitter_too(): void {
		Functions\when( 'aioseo' )->justReturn( (object) [] );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] ) );

		$meta = [ 'twitter:image' => 'https://example.com/what-the-plugin-resolved.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_a_throwing_plugin_costs_the_feature_not_the_page(): void {
		$exploding = new class() {
			public function getMetaData( $post = null ) {
				unset( $post );
				throw new \RuntimeException( 'plugin internals moved' );
			}
		};
		Functions\when( 'aioseo' )->justReturn( (object) [ 'meta' => (object) [ 'metaData' => $exploding ] ] );

		$image = 'https://example.com/what-the-plugin-resolved.jpg';
		$post = new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] );

		$this->assertSame( $image, SocialImageBridge::filterOpengraphImage( $image, [ $post, 'article' ] ) );
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
		$this->stubAioseoMeta( [ 'og_image_type' => 'default' ] );
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
