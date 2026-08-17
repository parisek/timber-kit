<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Unit\StarterBaseTestCase;

/**
 * The `gtm_container()` Twig function — which of the two sources prints.
 */
class GtmContainerTwigTest extends StarterBaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value ): mixed => $value
		);
		Functions\when( 'get_option' )->justReturn( array() );
	}

	private function render( array $overrides = array() ): string {
		$base = $this->createStarterBase( $overrides );

		ob_start();
		$base->twig_gtm_container();

		return (string) ob_get_clean();
	}

	public function test_an_unconfigured_project_prints_nothing_of_its_own(): void {
		$this->assertSame( '', $this->render() );
	}

	public function test_a_configured_project_prints_its_container(): void {
		$output = $this->render(
			array(
				'gtm_containers' => array(
					'default' => array(
						'id'     => 'GTM-N9FNXT1',
						'domain' => 'windstream.example.com',
						'path'   => '84jp8NTuqpqDvI/',
					),
				),
			)
		);

		$this->assertStringContainsString( "'https://windstream.example.com/84jp8NTuqpqDvI/'+dl;", $output );
		$this->assertStringNotContainsString( 'id=', $output );
	}

	public function test_the_current_language_selects_the_container(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value ): mixed => 'wpml_current_language' === $tag ? 'de' : $value
		);

		$output = $this->render(
			array(
				'gtm_containers' => array(
					'default' => array( 'id' => 'GTM-CZECH111' ),
					'de'      => array( 'id' => 'GTM-GERMAN11' ),
				),
			)
		);

		$this->assertStringContainsString( 'GTM-GERMAN11', $output );
		$this->assertStringNotContainsString( 'GTM-CZECH111', $output );
	}

	public function test_a_non_production_environment_prints_nothing(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );

		$output = $this->render(
			array( 'gtm_containers' => array( 'default' => array( 'id' => 'GTM-N9FNXT1' ) ) )
		);

		$this->assertSame( '', $output );
	}

	/**
	 * The plugin still printing its own container is the one state that
	 * double-counts every visit, so the kit stands down rather than adding
	 * a second loader.
	 */
	/**
	 * GTM4WP numbers its placements footer=0, body=1, body-auto=2, off=3.
	 * Reading `0` as "off" gets both halves of this wrong at once: the
	 * plugin's own default placement would go undetected and double-count,
	 * and a correctly switched-off plugin would suppress the kit's loader
	 * and stop measurement.
	 *
	 * @param int|null $placement Stored placement, NULL to omit the key.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[DataProvider('active_placement_provider')]
	public function test_the_kit_stands_down_for_every_placement_that_prints( ?int $placement ): void {
		$options = array( 'gtm-code' => 'GTM-N9FNXT1' );
		if ( NULL !== $placement ) {
			$options['gtm-code-placement'] = $placement;
		}

		Functions\when( 'get_option' )->justReturn( $options );

		$this->assertStringNotContainsString( 'gtm.start', $this->renderWithPluginLoaded() );
	}

	/** @return array<string, array{int|null}> */
	public static function active_placement_provider(): array {
		return array(
			'footer'         => array( 0 ),
			'body open'      => array( 1 ),
			'body open auto' => array( 2 ),
			'key absent'     => array( NULL ),
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_the_kit_prints_when_the_plugin_placement_is_off(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'gtm-code'           => 'GTM-N9FNXT1',
				'gtm-code-placement' => 3,
			)
		);

		$this->assertStringContainsString( 'gtm.start', $this->renderWithPluginLoaded() );
	}

	/**
	 * The plugin defines its own placement constants, so a future
	 * renumbering is read from the plugin rather than from a copy of its
	 * numbering that would silently go stale.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_the_off_value_comes_from_the_plugin_when_it_defines_one(): void {
		define( 'GTM4WP_PLACEMENT_OFF', 9 );
		Functions\when( 'get_option' )->justReturn(
			array(
				'gtm-code'           => 'GTM-N9FNXT1',
				'gtm-code-placement' => 9,
			)
		);

		$this->assertStringContainsString( 'gtm.start', $this->renderWithPluginLoaded() );
	}

	public function test_the_kit_prints_when_the_plugin_has_no_container_configured(): void {
		Functions\when( 'get_option' )->justReturn( array( 'gtm-code' => '' ) );

		$this->assertStringContainsString( 'gtm.start', $this->renderWithPluginLoaded() );
	}

	/**
	 * GTM4WP_VERSION is defined for the rest of the process once defined, so
	 * both plugin-loaded cases run in the same isolated process and the
	 * plugin-absent cases above never see it.
	 */
	private function renderWithPluginLoaded(): string {
		if ( ! defined( 'GTM4WP_VERSION' ) ) {
			define( 'GTM4WP_VERSION', '1.22.4' );
		}

		return $this->render(
			array( 'gtm_containers' => array( 'default' => array( 'id' => 'GTM-N9FNXT1' ) ) )
		);
	}
}
