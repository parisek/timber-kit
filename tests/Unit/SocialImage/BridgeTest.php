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
		// The `featured` branch asks whether the post has a renderable lead
		// image. Most tests here exercise another source, so a default keeps
		// them readable.
		$this->stubFeaturedImage( 11, 'https://example.com/the-featured-image.jpg' );
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

	/**
	 * Stub the post's featured image.
	 *
	 * @param int          $id  Attachment ID, 0 for a post with none.
	 * @param string|false $url What the attachment resolves to; false for a
	 *                          dangling ID whose attachment is gone.
	 */
	private function stubFeaturedImage( int $id, $url ): void {
		Functions\when( 'get_post_thumbnail_id' )->justReturn( $id );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( $url );
	}

	/**
	 * Stub AIOSEO with a working Facebook resolver.
	 *
	 * A Twitter deferral test without one passes for the wrong reason: the
	 * filter would also return the meta unchanged because it could not read an
	 * Open Graph image to copy.
	 *
	 * @param array<string, mixed> $props   Per-post meta properties.
	 * @param string               $og      The Open Graph image URL.
	 * @param array<string, mixed> $options AIOSEO's options object, as an array.
	 */
	private function stubAioseoWithOpengraph( array $props, string $og, array $options = [] ): void {
		$meta = (object) $props;
		$metaData = new class( $meta ) {
			public function __construct( private object $meta ) {}
			public function getMetaData( $post = null ) {
				unset( $post );
				return $this->meta;
			}
		};
		$facebook = new class( $og ) {
			public function __construct( private string $og ) {}
			public function getImage( $post = null ) {
				unset( $post );
				return [ $this->og, 1200, 630 ];
			}
		};
		$aioseo = [
			'meta'   => (object) [ 'metaData' => $metaData ],
			'social' => (object) [ 'facebook' => $facebook ],
		];

		if ( [] !== $options ) {
			$aioseo['options'] = (object) $options;
		}

		Functions\when( 'aioseo' )->justReturn( (object) $aioseo );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] ) );
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
		$this->assertTrue( SocialImageBridge::defersToEditor( 'custom' ) );
	}

	public function test_an_automatic_source_is_not_an_editor_choice(): void {
		// AIOSEO's Yoast importer writes one of these onto every post it
		// imports. Nobody chose them, and `content` on block content resolves
		// to nothing at all.
		foreach ( [ 'featured', 'content', 'attach', 'author', 'auto' ] as $source ) {
			$this->assertFalse( SocialImageBridge::defersToEditor( $source ), $source );
			$this->assertTrue( SocialImageBridge::isAutomatic( $source ), $source );
		}
		$this->assertFalse( SocialImageBridge::isAutomatic( 'default' ) );
		$this->assertFalse( SocialImageBridge::isAutomatic( 'custom_image' ) );
		$this->assertFalse( SocialImageBridge::isAutomatic( null ) );
		// Not an AIOSEO source; the plugin resolves it as `default`.
		$this->assertFalse( SocialImageBridge::isAutomatic( 'auth' ) );
	}

	public function test_no_per_post_choice_leaves_the_field_free(): void {
		$this->assertFalse( SocialImageBridge::defersToEditor( 'default' ) );
		$this->assertFalse( SocialImageBridge::defersToEditor( '' ) );
		$this->assertFalse( SocialImageBridge::defersToEditor( null ) );
	}

	public function test_a_default_post_takes_the_global_source(): void {
		// AIOSEO reads the global source for a post left on `default`, so a
		// site whose global source is `featured` resolves the featured image
		// there. Deciding on the per-post value alone would treat that as
		// "no source" and let the mapped field replace the featured image.
		$this->assertSame( 'featured', SocialImageBridge::effectiveSource( 'default', 'featured' ) );
		$this->assertSame( 'featured', SocialImageBridge::effectiveSource( '', 'featured' ) );
		$this->assertSame( 'featured', SocialImageBridge::effectiveSource( null, 'featured' ) );
		$this->assertSame( 'custom_image', SocialImageBridge::effectiveSource( 'custom_image', 'featured' ) );
		$this->assertSame( 'default', SocialImageBridge::effectiveSource( 'default', null ) );
		$this->assertSame( 'default', SocialImageBridge::effectiveSource( 'default', '' ) );
	}

	public function test_a_global_featured_source_keeps_the_featured_image(): void {
		$source = SocialImageBridge::effectiveSource( 'default', 'featured' );

		$this->assertFalse( SocialImageBridge::shouldSupply( $source, true ) );
		$this->assertTrue( SocialImageBridge::shouldSupply( $source, false ) );
	}

	public function test_the_featured_decision_asks_the_post_not_the_url(): void {
		// A featured image that is also the site's default social image is still
		// the post's own picture. Comparing the resolved URL against the plugin's
		// fallbacks read that as a fallback and replaced it with the field image.
		$this->assertFalse( SocialImageBridge::shouldSupply( 'featured', true ) );
		$this->assertTrue( SocialImageBridge::shouldSupply( 'featured', false ) );
	}

	public function test_an_automatic_source_keeps_an_image_it_found(): void {
		$this->stubAioseoMeta( [ 'og_image_type' => 'featured' ] );

		$image = 'https://example.com/the-featured-image.jpg';
		$post = new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] );

		// No field map is wired: reaching resolution would fail on a missing mock.
		$this->assertSame( $image, SocialImageBridge::filterOpengraphImage( $image, [ $post, 'article' ] ) );
	}

	public function test_a_dangling_thumbnail_id_is_not_a_featured_image(): void {
		// `has_post_thumbnail()` answers yes for an ID whose attachment is gone.
		// The post then has no picture, AIOSEO falls back to its default image,
		// and standing aside would leave an image that is not the post's.
		$this->stubAioseoMeta( [ 'og_image_type' => 'featured' ] );
		$this->stubFeaturedImage( 11, false );

		$post = new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post_type' )->justReturn( 'project' );
		// The attachment is gone, so nothing resolves and toTuple() keeps the
		// plugin's image either way. Asserting the returned image would therefore
		// pass whichever branch ran, so this watches for the one call that only
		// happens past the deferral.
		$reached = false;
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) use ( &$reached ) {
			unset( $id );
			$reached = true;
			return null;
		} );
		$image = 'https://example.com/site-default.png';

		$this->assertSame( $image, SocialImageBridge::filterOpengraphImage( $image, [ $post, 'article' ] ) );
		$this->assertTrue( $reached, 'the bridge stood aside on an assigned but unusable thumbnail' );
	}

	public function test_the_supply_decision_per_source(): void {
		// No source, or `default`: the bridge always supplies, as before.
		$this->assertTrue( SocialImageBridge::shouldSupply( null, true ) );
		$this->assertTrue( SocialImageBridge::shouldSupply( '', true ) );
		$this->assertTrue( SocialImageBridge::shouldSupply( 'default', true ) );
		// An editor's image: never.
		$this->assertFalse( SocialImageBridge::shouldSupply( 'custom_image', false ) );
		$this->assertFalse( SocialImageBridge::shouldSupply( 'custom', false ) );
		// `featured`: keep the post's own lead image; supply only where there is none.
		$this->assertFalse( SocialImageBridge::shouldSupply( 'featured', true ) );
		$this->assertTrue( SocialImageBridge::shouldSupply( 'featured', false ) );
		// The other automatic sources pick whatever image turns up in the body,
		// an attachment or the author's avatar — a CTA banner as often as not.
		// The mapped preview is the better picture, featured image or not.
		foreach ( [ 'content', 'attach', 'author', 'auto' ] as $source ) {
			$this->assertTrue( SocialImageBridge::shouldSupply( $source, true ), $source );
			$this->assertTrue( SocialImageBridge::shouldSupply( $source, false ), $source );
		}
		// An unknown source is treated as a choice: never override what we cannot name.
		$this->assertFalse( SocialImageBridge::shouldSupply( 'something-new', false ) );
		$this->assertFalse( SocialImageBridge::shouldSupply( 'auth', false ) );
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
		// The Open Graph resolver works and answers a different URL, so an
		// implementation that decided again here would change the tag.
		$this->stubAioseoWithOpengraph(
			[ 'twitter_use_og' => true, 'og_image_type' => 'custom_image', 'twitter_image_type' => 'default' ],
			'https://example.com/the-open-graph-image.jpg'
		);

		$meta = [ 'twitter:image' => 'https://example.com/what-the-editor-picked.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_twitter_defers_to_an_editor_chosen_twitter_image(): void {
		$this->stubAioseoWithOpengraph(
			[ 'twitter_use_og' => false, 'twitter_image_type' => 'custom_image' ],
			'https://example.com/the-open-graph-image.jpg'
		);

		$meta = [ 'twitter:image' => 'https://example.com/what-the-editor-picked.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_twitter_follows_the_open_graph_image_it_resolved(): void {
		// Twitter's own fallback is the Open Graph image, but the bridge used to
		// write a separate preview here, so a post whose editor picked the og
		// image shared a different picture on X.
		$facebook = new class() {
			public function getImage( $post = null ) {
				unset( $post );
				return [ 'https://example.com/what-the-editor-picked.jpg', 1200, 630 ];
			}
		};
		$metaData = new class() {
			public function getMetaData( $post = null ) {
				unset( $post );
				return (object) [ 'twitter_use_og' => false, 'twitter_image_type' => 'default', 'og_image_type' => 'custom_image' ];
			}
		};
		Functions\when( 'aioseo' )->justReturn( (object) [ 'meta' => (object) [ 'metaData' => $metaData ], 'social' => (object) [ 'facebook' => $facebook ] ] );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] ) );

		$result = SocialImageBridge::filterTwitterTags( [ 'twitter:image' => 'https://example.com/field.jpeg' ] );

		$this->assertSame( 'https://example.com/what-the-editor-picked.jpg', $result['twitter:image'] );
	}

	public function test_twitter_is_untouched_when_the_open_graph_image_cannot_be_read(): void {
		$this->stubAioseoMeta( [ 'twitter_use_og' => false, 'twitter_image_type' => 'default' ] );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] ) );

		$meta = [ 'twitter:image' => 'https://example.com/what-the-plugin-resolved.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_twitter_defers_to_a_global_custom_twitter_source(): void {
		// AIOSEO reads the global Twitter source for a post left on `default`,
		// exactly as it does for Open Graph. Deciding on the per-post value alone
		// took a site whose global Twitter source is `custom` for "no choice" and
		// wrote the Open Graph image over the editor's card image.
		//
		// The Open Graph resolver works here on purpose: without it the filter
		// would return the meta unchanged for want of an image to copy, and the
		// test would pass however the decision went.
		$this->stubAioseoWithOpengraph(
			[ 'twitter_use_og' => false, 'twitter_image_type' => 'default' ],
			'https://example.com/the-open-graph-image.jpg',
			[ 'social' => (object) [ 'twitter' => (object) [ 'general' => (object) [ 'defaultImageSourcePosts' => 'custom' ] ] ] ]
		);

		$meta = [ 'twitter:image' => 'https://example.com/what-the-editor-picked.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_twitter_defers_to_a_source_it_cannot_name(): void {
		// Same contract as Open Graph: a source this code cannot name is a choice.
		$this->stubAioseoWithOpengraph(
			[ 'twitter_use_og' => false, 'twitter_image_type' => 'a-source-from-a-later-release' ],
			'https://example.com/the-open-graph-image.jpg'
		);

		$meta = [ 'twitter:image' => 'https://example.com/what-the-plugin-resolved.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_twitter_keeps_a_featured_card_image(): void {
		// Under `featured` with a featured image present, AIOSEO put the post's
		// own picture on the card. That is the picture the bridge wants there.
		$this->stubAioseoWithOpengraph(
			[ 'twitter_use_og' => false, 'twitter_image_type' => 'featured' ],
			'https://example.com/the-open-graph-image.jpg'
		);
		$this->stubFeaturedImage( 11, 'https://example.com/the-featured-image.jpg' );

		$meta = [ 'twitter:image' => 'https://example.com/the-featured-image.jpg' ];

		$this->assertSame( $meta, SocialImageBridge::filterTwitterTags( $meta ) );
	}

	public function test_twitter_supplies_under_featured_when_the_post_has_none(): void {
		$this->stubAioseoWithOpengraph(
			[ 'twitter_use_og' => false, 'twitter_image_type' => 'featured' ],
			'https://example.com/the-open-graph-image.jpg'
		);
		$this->stubFeaturedImage( 0, false );

		$result = SocialImageBridge::filterTwitterTags( [ 'twitter:image' => 'https://example.com/site-default.png' ] );

		$this->assertSame( 'https://example.com/the-open-graph-image.jpg', $result['twitter:image'] );
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
