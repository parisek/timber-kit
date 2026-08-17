<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
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
	 * The loader never reads the plugin's settings. Guessing at a schema
	 * this kit does not own can only get it wrong in the direction that
	 * stops measurement, so the duplicate-container state is diagnosed in
	 * Site Health instead - see GtmContainerNotDuplicated.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_the_kit_prints_even_while_the_plugin_is_loaded(): void {
		define( 'GTM4WP_VERSION', '1.22.4' );
		Functions\when( 'get_option' )->justReturn(
			array(
				'gtm-code'           => 'GTM-N9FNXT1',
				'gtm-code-placement' => 1,
			)
		);

		$output = $this->render(
			array( 'gtm_containers' => array( 'default' => array( 'id' => 'GTM-N9FNXT1' ) ) )
		);

		$this->assertStringContainsString( 'gtm.start', $output );
	}

	public function test_a_language_written_out_and_left_blank_prints_nothing(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value ): mixed => 'wpml_current_language' === $tag ? 'de' : $value
		);

		$output = $this->render(
			array(
				'gtm_containers' => array(
					'default' => array( 'id' => 'GTM-CZECH111' ),
					'de'      => '',
				),
			)
		);

		$this->assertSame( '', $output );
	}

}
