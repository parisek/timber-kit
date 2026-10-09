<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Twig\UniqueIdExtension;
use Tests\Unit\StarterBaseTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * `uniqueId()` must reach the Twig environment through `timber_twig()`. A
 * class that exists but is never registered would leave every component that
 * calls it with "Unknown function" at render time.
 */
class UniqueIdWiringTest extends StarterBaseTestCase {

	public function test_timber_twig_registers_unique_id(): void {
		Functions\when( 'get_template_directory' )->justReturn( '/theme' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'add_filter' )->justReturn( true );
		$base = $this->createStarterBase();

		$env = new Environment( new ArrayLoader() );
		$base->timber_twig( $env );

		$this->assertTrue( $env->hasExtension( UniqueIdExtension::class ) );
		$this->assertNotNull( $env->getFunction( 'uniqueId' ) );
	}
}
