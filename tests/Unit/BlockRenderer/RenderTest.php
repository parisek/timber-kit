<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class RenderTest extends BlockRendererTestCase {

	protected function setUp(): void {
		parent::setUp();
		Fixtures::resetPreviewMemo();

		// Default no-op mocks for WP functions every render path touches.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value, mixed ...$rest ) => $value
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);
		Functions\when( 'wp_scripts' )->alias(
			static fn() => (object) [ 'queue' => [] ]
		);
		Functions\when( 'wp_styles' )->alias(
			static fn() => (object) [ 'queue' => [] ]
		);
		Functions\when( 'acf_get_valid_post_id' )->justReturn( 0 );
		Functions\when( 'get_query_var' )->justReturn( 0 );

		// ACF / Helpers::formatFields() function mocks. formatFields() is called
		// with a numeric $post_id, so isOptionsPostId() short-circuits on
		// is_string() → false; isNavMenuItemPostId() skips get_post_type() when
		// the function doesn't exist; get_field_objects() is the default path.
		Functions\when( 'get_field_objects' )->justReturn( false );

		// WordPress conditional tag stubs needed by Timber::context().
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_archive' )->justReturn( false );

		// WordPress function stubs needed by Timber::compile() → Loader → LocationManager.
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/tmp' );
		Functions\when( 'get_template_directory' )->justReturn( '/tmp' );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( $s, '/' ) . '/' );
		Functions\when( 'apply_filters_deprecated' )->alias(
			static fn( string $tag, array $args, string $version, string $replacement = '' ): mixed => $args[0]
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'do_action_deprecated' )->justReturn( null );

		// Pre-populate Timber's context cache to bypass Site instantiation
		// (which requires is_multisite() and many more WP functions). The
		// cache being non-empty causes context_global() to skip new Site().
		\Timber\Timber::$context_cache = [ 'site' => null, 'user' => false ];
	}

	protected function tearDown(): void {
		// Reset Timber's context cache so it doesn't leak between tests.
		\Timber\Timber::$context_cache = [];
		parent::tearDown();
	}

	public function test_real_post_id_resolution_falls_back_to_global_post(): void {
		// When callback $post_id is a "block_*" string and ACF resolves it to a
		// "block_*" string too, the renderer must fall back to global $post->ID
		// for the cache group naming.
		$GLOBALS['post'] = (object) [ 'ID' => 42 ];

		Functions\when( 'acf_get_valid_post_id' )->justReturn( 'block_abc123' );

		$captured_group = null;
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\expect( 'wp_cache_get' )
			->andReturnUsing( function ( string $key, string $group ) use ( &$captured_group ) {
				$captured_group = $group;
				return false;
			} );

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			false,
			'block_abc123',
			null
		);
		ob_end_clean();

		$this->assertSame( 'acf_block_42', $captured_group );

		unset( $GLOBALS['post'] );
	}

	public function test_cache_key_includes_all_seven_fields(): void {
		$captured_cache_data = null;
		$captured_key        = null;

		Functions\when( 'get_query_var' )->justReturn( 3 );

		// Override apply_filters to capture the cache_key call while passing through others.
		// The setUp alias is overridden here so we can capture the specific filter arguments.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ) use ( &$captured_cache_data, &$captured_key ): mixed {
				if ( $tag === 'timber_kit/block_renderer/cache_key' ) {
					$captured_key        = $value;
					$captured_cache_data = $rest[0] ?? null;
					return $value;
				}
				if ( $tag === 'wpml_current_language' ) {
					return 'cs';
				}
				return $value;
			}
		);

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes( [
				'anchor'    => 'my-anchor',
				'className' => 'is-style-big',
			] ),
			'',
			true,
			0,
			null
		);
		ob_end_clean();

		$this->assertNotNull( $captured_cache_data );
		$this->assertSame(
			[ 'name', 'data', 'anchor', 'className', 'post_id', 'lang', 'paged' ],
			array_keys( $captured_cache_data ),
			'cache_data must contain exactly these 7 keys in this order'
		);
		$this->assertSame( 'my-anchor', $captured_cache_data['anchor'] );
		$this->assertSame( 'is-style-big', $captured_cache_data['className'] );
		$this->assertSame( 'cs', $captured_cache_data['lang'] );
		$this->assertSame( 3, $captured_cache_data['paged'] );

		$this->assertStringStartsWith( 'acf_block_', $captured_key );
		$this->assertSame( 32 + 10, strlen( $captured_key ), 'key = "acf_block_" (10) + md5 (32)' );
	}

	public function test_frontend_cache_skipped_when_block_has_dynamic_filter(): void {
		Functions\when( 'has_filter' )->alias(
			static fn( string $name ): bool => $name === 'block_article_featured_content'
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );

		// wp_cache_get MUST NOT be called when block has a dynamic filter.
		Functions\expect( 'wp_cache_get' )->never();

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(), // name = "acf/article-featured" → filter name "block_article_featured_content"
			'',
			false, // frontend, not preview
			0,
			null
		);
		ob_end_clean();

		// Brain Monkey enforces the never() expectation in tearDown; acknowledge it here.
		$this->addToAssertionCount( 1 );
	}

	public function test_use_cache_filter_can_disable_per_block(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );

		// Override apply_filters to return false for the use_cache filter.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ): mixed {
				if ( $tag === 'timber_kit/block_renderer/use_cache' ) {
					return false;
				}
				return $value;
			}
		);

		Functions\expect( 'wp_cache_get' )->never();

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			false,
			0,
			null
		);
		ob_end_clean();

		// Brain Monkey enforces the never() expectation in tearDown; acknowledge it here.
		$this->addToAssertionCount( 1 );
	}

	public function test_inserter_preview_skips_content_filter(): void {
		// Inserter preview: empty post fields + has attributes.data → discriminator true → skip content filter.
		// Template filter still runs.
		$content_filter_called  = false;
		$template_filter_called = false;

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ) use ( &$content_filter_called, &$template_filter_called ): mixed {
				if ( $tag === 'block_article_featured_content' ) {
					$content_filter_called = true;
				}
				if ( $tag === 'block_article_featured_template' ) {
					$template_filter_called = true;
				}
				return $value;
			}
		);

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes( [ 'data' => [ 'title' => 'Example' ] ] ),
			'',
			true,  // is_preview = true
			0,
			null
		);
		ob_end_clean();

		$this->assertFalse( $content_filter_called, 'block_<name>_content must NOT fire during inserter preview' );
		$this->assertTrue( $template_filter_called, 'block_<name>_template must always fire' );
	}

	public function test_frontend_render_runs_content_filter(): void {
		// Frontend render (is_preview = false): discriminator short-circuits → content filter runs.
		$content_filter_called  = false;
		$template_filter_called = false;

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ) use ( &$content_filter_called, &$template_filter_called ): mixed {
				if ( $tag === 'block_article_featured_content' ) {
					$content_filter_called = true;
				}
				if ( $tag === 'block_article_featured_template' ) {
					$template_filter_called = true;
				}
				return $value;
			}
		);

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			false, // not preview
			123,
			null
		);
		ob_end_clean();

		$this->assertTrue( $content_filter_called, 'content filter must fire on frontend/editor-canvas renders' );
		$this->assertTrue( $template_filter_called );
	}

	public function test_template_filter_runs_in_all_modes(): void {
		$template_filter_called = false;

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ) use ( &$template_filter_called ): mixed {
				if ( $tag === 'block_article_featured_template' ) {
					$template_filter_called = true;
				}
				return $value;
			}
		);

		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', true, 0, null );
		ob_end_clean();

		$this->assertTrue( $template_filter_called );
	}

	public function test_empty_template_renders_alert_for_logged_in_users(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes( [ 'title' => 'Article — Featured' ] ),
			'',
			false,
			0,
			null
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'block-editor-warning', $output );
		$this->assertStringContainsString( 'timber-kit-block-empty', $output );
		$this->assertStringContainsString( 'data-block="acf/article-featured"', $output );
		$this->assertStringContainsString( 'Pro zobrazení vyplňte', $output );
		$this->assertStringContainsString( 'Article — Featured', $output );
	}

	public function test_empty_alert_html_filter_replaces_default_output(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );

		// Override the apply_filters mock to intercept just the empty_alert_html dispatch.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ): mixed {
				if ( $tag === 'timber_kit/block_renderer/empty_alert_html' ) {
					return '<custom-theme-alert>OVERRIDE</custom-theme-alert>';
				}
				return $value;
			}
		);

		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', false, 0, null );
		$output = ob_get_clean();

		$this->assertSame( '<custom-theme-alert>OVERRIDE</custom-theme-alert>', $output );
	}

	public function test_side_effecting_block_excluded_from_cache(): void {
		// Cache write path enabled (no dynamic filter, external cache available, cache miss).
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );

		// Simulate side effect: scripts queue grows during render.
		$call_count = 0;
		Functions\when( 'wp_scripts' )->alias( static function () use ( &$call_count ): object {
			$call_count++;
			return (object) [ 'queue' => $call_count === 1 ? [] : [ 'wpforms-frontend' ] ];
		} );

		// Inject non-empty output via the empty_alert_html filter so cache-write branch is reachable.
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ): mixed {
				if ( $tag === 'timber_kit/block_renderer/empty_alert_html' ) {
					return '<synthetic-output>';
				}
				return $value;
			}
		);

		// wp_cache_set MUST NOT be called when side-effects fired.
		Functions\expect( 'wp_cache_set' )->never();

		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', false, 0, null );
		ob_end_clean();

		// Brain Monkey enforces the never() expectation in tearDown; acknowledge it here.
		$this->addToAssertionCount( 1 );
	}

	public function test_preview_memo_cache_hit_short_circuits(): void {
		// Inject non-empty output so the memo-write branch fires.
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ): mixed {
				if ( $tag === 'timber_kit/block_renderer/empty_alert_html' ) {
					return '<synthetic-output>';
				}
				return $value;
			}
		);

		// First render — populates the preview memo.
		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', true, 0, null );
		$first = ob_get_clean();

		// Count wp_styles calls — only fires inside the render body, not when the memo hits.
		$styles_calls = 0;
		Functions\when( 'wp_styles' )->alias( static function () use ( &$styles_calls ): object {
			$styles_calls++;
			return (object) [ 'queue' => [] ];
		} );

		// Second render — should hit memo, never enter the render body (wp_styles not called).
		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', true, 0, null );
		$second = ob_get_clean();

		$this->assertSame( $first, $second );
		$this->assertSame( 0, $styles_calls, 'second render must hit memo and skip the render body' );
	}

	public function test_empty_alert_not_cached_for_anonymous_visitors(): void {
		// Critical: logged-in editor renders an empty block → empty alert HTML
		// would have been written to the shared frontend cache. Anonymous visitors
		// would then hit that cache and see the editor's warning. Must skip
		// cache write when the empty-alert path fires.

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );

		// wp_cache_set MUST NOT be called when the empty-alert path renders.
		Functions\expect( 'wp_cache_set' )->never();

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			false, // frontend render (not preview) — this is the dangerous path
			0,
			null
		);
		ob_end_clean();

		$this->addToAssertionCount( 1 );
	}

	public function test_inserter_preview_wraps_in_16_9_aspect_ratio(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );

		// Inject non-empty output via empty_alert_html (renderEmptyAlert path).
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$rest ): mixed {
				if ( $tag === 'timber_kit/block_renderer/empty_alert_html' ) {
					return '<p>Synthetic preview body</p>';
				}
				return $value;
			}
		);

		ob_start();
		BlockRenderer::render(
			// $is_preview=true + attributes.data + Helpers::formatFields() returns [] (default mock)
			// → discriminator returns true → aspect-ratio wrap should apply
			Fixtures::attributes( [ 'data' => [ 'title' => 'Example' ] ] ),
			'',
			true,
			0,
			null
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'aspect-ratio: 16/9', $output );
		$this->assertStringContainsString( 'overflow: hidden', $output );
		$this->assertStringContainsString( '<p>Synthetic preview body</p>', $output );
	}
}
