<?php

declare(strict_types=1);

namespace Tests\Integration;

use Parisek\TimberKit\StarterBase;

if ( defined( 'TIMBERKIT_INTEGRATION_SKIP' ) ) {
	/**
	 * Stand-in when no test database answers. WordPress is not loaded, so
	 * WP_UnitTestCase does not exist; every test is skipped with the reason.
	 */
	abstract class IntegrationTestCase extends \PHPUnit\Framework\TestCase {

		protected function setUp(): void {
			self::markTestSkipped( (string) TIMBERKIT_INTEGRATION_SKIP );
		}
	}
} else {
	/**
	 * Base for tests that run the kit inside real WordPress.
	 *
	 * WP_UnitTestCase runs each test in a database transaction and rolls it
	 * back, and it restores the hook registry after each test. So a kit booted
	 * in one test leaves no hooks and no rows behind for the next.
	 */
	abstract class IntegrationTestCase extends \WP_UnitTestCase {

		public function set_up(): void {
			parent::set_up();
			// Core keeps one REST server per request. Drop it, so each test
			// builds its routes after its own kit is booted.
			$GLOBALS['wp_rest_server'] = null;
		}

		public function tear_down(): void {
			$GLOBALS['wp_rest_server'] = null;
			parent::tear_down();
		}

		/**
		 * Core's version first reads `@expectedDeprecated` doc-comment
		 * annotations through PHPUnit\Util\Test::parseTestMethodAnnotations(),
		 * which PHPUnit 10 removed. Every test would error in set_up(). Core's
		 * own suite still runs PHPUnit 9, so the core test library has not
		 * caught up (checked against wordpress-develop trunk).
		 *
		 * This copy keeps the rest of core's method unchanged: the hooks that
		 * make an unexpected deprecation or _doing_it_wrong() fail the test.
		 * Declare an expected one with setExpectedDeprecated() or
		 * setExpectedIncorrectUsage(), not with an annotation.
		 */
		public function expectDeprecated() {
			add_action( 'deprecated_function_run', array( $this, 'deprecated_function_run' ), 10, 3 );
			add_action( 'deprecated_argument_run', array( $this, 'deprecated_function_run' ), 10, 3 );
			add_action( 'deprecated_class_run', array( $this, 'deprecated_function_run' ), 10, 3 );
			add_action( 'deprecated_file_included', array( $this, 'deprecated_function_run' ), 10, 4 );
			add_action( 'deprecated_hook_run', array( $this, 'deprecated_function_run' ), 10, 4 );
			add_action( 'doing_it_wrong_run', array( $this, 'doing_it_wrong_run' ), 10, 3 );

			add_action( 'deprecated_function_trigger_error', '__return_false' );
			add_action( 'deprecated_argument_trigger_error', '__return_false' );
			add_action( 'deprecated_class_trigger_error', '__return_false' );
			add_action( 'deprecated_file_trigger_error', '__return_false' );
			add_action( 'deprecated_hook_trigger_error', '__return_false' );
			add_action( 'doing_it_wrong_trigger_error', '__return_false' );
		}

		/**
		 * Boot StarterBase the way a theme does, with the given flags set
		 * before the constructor registers its hooks.
		 *
		 * @param array<string, mixed> $flags Protected property values, keyed by name.
		 */
		protected function bootKit( array $flags = array() ): StarterBase {
			return new class( $flags ) extends StarterBase {

				/**
				 * @param array<string, mixed> $flags
				 */
				public function __construct( array $flags ) {
					foreach ( $flags as $name => $value ) {
						$this->$name = $value;
					}
					parent::__construct();
				}
			};
		}
	}
}
