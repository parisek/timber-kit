<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Parisek\TimberKit\Health\Check\GtmContainerNotDuplicated;
use Parisek\TimberKit\Health\Result;
use Tests\Unit\Health\HealthTestCase;

/**
 * The one GTM state a developer cannot see: two loaders, doubled numbers.
 */
class GtmContainerNotDuplicatedTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	private function options( array $options ): void {
		Functions\when( 'get_option' )->justReturn( $options );
	}

	public function test_an_unconfigured_theme_leaves_the_plugin_alone(): void {
		$this->options( array( 'gtm-code' => 'GTM-N9FNXT1' ) );

		$this->assertSame( Result::GOOD, ( new GtmContainerNotDuplicated( false ) )->run()->status() );
	}

	public function test_a_configured_theme_without_the_plugin_is_fine(): void {
		$this->options( array() );

		$this->assertSame( Result::GOOD, ( new GtmContainerNotDuplicated( true ) )->run()->status() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_both_sources_printing_is_critical(): void {
		define( 'GTM4WP_VERSION', '1.22.4' );
		$this->options(
			array(
				'gtm-code'           => 'GTM-N9FNXT1',
				'gtm-code-placement' => 1,
			)
		);

		$result = ( new GtmContainerNotDuplicated( true ) )->run();

		$this->assertSame( Result::CRITICAL, $result->status() );
		$this->assertStringContainsString( 'OFF', $result->actions() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_the_plugin_switched_off_is_fine(): void {
		define( 'GTM4WP_VERSION', '1.22.4' );
		$this->options(
			array(
				'gtm-code'           => 'GTM-N9FNXT1',
				'gtm-code-placement' => 3,
			)
		);

		$this->assertSame( Result::GOOD, ( new GtmContainerNotDuplicated( true ) )->run()->status() );
	}

	/**
	 * Placement 0 is the plugin's own default and means footer, not off.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_the_plugin_on_its_default_placement_still_prints(): void {
		define( 'GTM4WP_VERSION', '1.22.4' );
		$this->options( array( 'gtm-code' => 'GTM-N9FNXT1' ) );

		$this->assertSame( Result::CRITICAL, ( new GtmContainerNotDuplicated( true ) )->run()->status() );
	}

	/**
	 * A site can keep its container ID out of the database entirely, so an
	 * empty stored option does not mean the plugin has nothing to print.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_a_hardcoded_plugin_id_counts_as_configured(): void {
		define( 'GTM4WP_VERSION', '1.22.4' );
		define( 'GTM4WP_HARDCODED_GTM_ID', 'GTM-N9FNXT1' );
		$this->options( array( 'gtm-code' => '' ) );

		$this->assertSame( Result::CRITICAL, ( new GtmContainerNotDuplicated( true ) )->run()->status() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_a_plugin_with_no_container_at_all_prints_nothing(): void {
		define( 'GTM4WP_VERSION', '1.22.4' );
		$this->options( array( 'gtm-code' => '' ) );

		$this->assertSame( Result::GOOD, ( new GtmContainerNotDuplicated( true ) )->run()->status() );
	}
}
