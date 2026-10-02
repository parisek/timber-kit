<?php

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

abstract class StarterBaseTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Create a StarterBase instance with mocked WordPress dependencies.
	 * Uses an anonymous child class since StarterBase is designed to be extended.
	 */
	protected function createStarterBase( array $overrides = [] ): \Parisek\TimberKit\StarterBase {
		// Mock all WordPress functions called in constructor
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'wp_get_theme' )->justReturn( new class {
			public function get( string $key ): string {
				return 'test_theme';
			}
		} );

		$base = new class extends \Parisek\TimberKit\StarterBase {
			public function __construct() {
				// Skip parent constructor to avoid hook registration
			}

			/**
			 * No SEO plugin by default. The real lookup asks function_exists(),
			 * and a Brain\Monkey stub defined by an earlier test outlives it,
			 * so the answer would depend on test order.
			 *
			 * @var array{plugin: ?string, enabled: ?bool}
			 */
			public array $breadcrumbSchemaState = array( 'plugin' => null, 'enabled' => null );

			protected function breadcrumb_schema_state(): array {
				return $this->breadcrumbSchemaState;
			}

			public function setProperty( string $name, mixed $value ): void {
				$this->$name = $value;
			}
		};

		foreach ( $overrides as $key => $value ) {
			$base->setProperty( $key, $value );
		}

		return $base;
	}
}
