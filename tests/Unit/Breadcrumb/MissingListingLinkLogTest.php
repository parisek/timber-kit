<?php

declare(strict_types=1);

namespace Tests\Unit\Breadcrumb;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Breadcrumb;

/**
 * A `list_page_map` entry whose `links` option has no url/title in the current
 * request drops the listing step. On a WPML + ACFML site that is the normal
 * state of a translatable option nobody saved in the secondary language, so the
 * step disappears in that language only, with nothing to explain why.
 *
 * The drop stays; under WP_DEBUG it is now reported. These tests pin both
 * halves: the report names what is missing, and nothing is logged when debug
 * is off or when the link resolves.
 */
final class MissingListingLinkLogTest extends BreadcrumbTestCase {

	private string $log_file = '';

	private string $previous_log = '';

	/**
	 * Redirect error_log into a file this test owns.
	 *
	 * Called from the test body, not setUp(): PHPUnit 12 installs its own
	 * error_log redirect after setUp() returns, which would silently replace
	 * one made there and leave every log assertion reading an empty file.
	 */
	private function capture_error_log(): void {
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'tk-breadcrumb-' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		if ( '' !== $this->log_file ) {
			ini_set( 'error_log', $this->previous_log );
		}
		if ( '' !== $this->log_file && is_file( $this->log_file ) ) {
			unlink( $this->log_file );
		}
		parent::tearDown();
	}

	private function logged(): string {
		clearstatcache();
		return is_file( $this->log_file ) ? (string) file_get_contents( $this->log_file ) : '';
	}

	/**
	 * A Breadcrumb whose debug switch is forced, because WP_DEBUG is a constant
	 * the test bootstrap has already defined as false.
	 *
	 * @param array<string, mixed> $config
	 */
	private function breadcrumb( array $config, bool $debug ): Breadcrumb {
		return new class( $config, $debug ) extends Breadcrumb {
			/** @param array<string, mixed> $config */
			public function __construct( array $config, private bool $debug ) {
				parent::__construct( $config );
			}

			protected function debug_enabled(): bool {
				return $this->debug;
			}
		};
	}

	private function arrange_singular_post(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_queried_object' )->justReturn( (object) [ 'ID' => 50, 'post_title' => 'My Article' ] );
	}

	public function test_logs_the_missing_key_post_type_and_language_when_debug_is_on(): void {
		$this->capture_error_log();
		$this->arrange_singular_post();
		Functions\when( 'get_field' )->justReturn( null );
		Filters\expectApplied( 'wpml_current_language' )->andReturn( 'en' );

		$bc     = $this->breadcrumb( [ 'list_page_map' => [ 'post' => 'article_list' ] ], true );
		$result = $this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertSame( [ [ 'type' => 'item', 'title' => 'My Article', 'url' => null ] ], $result );
		$log = $this->logged();
		$this->assertStringContainsString( '[timber_kit/breadcrumb]', $log );
		$this->assertStringContainsString( 'links.article_list', $log );
		$this->assertStringContainsString( 'post_type=post', $log );
		$this->assertStringContainsString( 'lang=en', $log );
	}

	public function test_logs_when_the_links_group_lacks_the_mapped_key(): void {
		$this->capture_error_log();
		$this->arrange_singular_post();
		// The group exists and carries another key, but not the mapped one.
		Functions\when( 'get_field' )->justReturn( [ 'header_button' => false ] );

		$bc = $this->breadcrumb( [ 'list_page_map' => [ 'post' => 'article_list' ] ], true );
		$this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertStringContainsString( 'links.article_list', $this->logged() );
	}

	public function test_is_silent_when_debug_is_off(): void {
		$this->capture_error_log();
		$this->arrange_singular_post();
		Functions\when( 'get_field' )->justReturn( null );

		$bc = $this->breadcrumb( [ 'list_page_map' => [ 'post' => 'article_list' ] ], false );
		$this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertSame( '', $this->logged() );
	}

	public function test_is_silent_when_the_listing_link_resolves(): void {
		$this->capture_error_log();
		$this->arrange_singular_post();
		Functions\when( 'get_field' )->justReturn( [
			'article_list' => [ 'url' => 'https://example.test/blog/', 'title' => 'Blog' ],
		] );
		Functions\when( 'url_to_postid' )->justReturn( 42 );
		Filters\expectApplied( 'wpml_object_id' )->andReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/blog/' );

		$bc     = $this->breadcrumb( [ 'list_page_map' => [ 'post' => 'article_list' ] ], true );
		$result = $this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertSame( 'Blog', $result[0]['title'] );
		$this->assertSame( '', $this->logged() );
	}

	public function test_debug_switch_follows_wp_debug_by_default(): void {
		$bc = new Breadcrumb();
		$this->assertSame(
			\defined( 'WP_DEBUG' ) && WP_DEBUG,
			$this->invoke_protected( $bc, 'debug_enabled' )
		);
	}
}
