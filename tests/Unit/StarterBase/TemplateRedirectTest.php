<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class TemplateRedirectTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_query'] );
		parent::tearDown();
	}

	private function createWpQuery( int $page = 0 ): object {
		return new class( $page ) {
			public array $vars = [];

			public function __construct( int $page ) {
				$this->vars['page'] = $page;
			}

			public function get( string $key ): mixed {
				return $this->vars[ $key ] ?? '';
			}

			public function set( string $key, mixed $value ): void {
				$this->vars[ $key ] = $value;
			}
		};
	}

	public function test_converts_page_to_paged_on_singular_post(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );

		$wp_query = $this->createWpQuery( 3 );
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->template_redirect();

		$this->assertSame( 1, $wp_query->vars['page'] );
		$this->assertSame( 3, $wp_query->vars['paged'] );
	}

	public function test_does_not_convert_page_1(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );

		$wp_query = $this->createWpQuery( 1 );
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->template_redirect();

		$this->assertSame( 1, $wp_query->vars['page'] );
		$this->assertArrayNotHasKey( 'paged', $wp_query->vars );
	}

	public function test_skips_non_singular_post(): void {
		Functions\when( 'is_singular' )->justReturn( false );

		$wp_query = $this->createWpQuery( 3 );
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->template_redirect();

		// Page should remain unchanged
		$this->assertSame( 3, $wp_query->vars['page'] );
		$this->assertArrayNotHasKey( 'paged', $wp_query->vars );
	}
}
