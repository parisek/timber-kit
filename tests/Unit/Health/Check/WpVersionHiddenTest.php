<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\WpVersionHidden;
use Parisek\TimberKit\Health\HealthCheck;
use Tests\Unit\Health\HealthTestCase;

class WpVersionHiddenTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'get_the_generator' )->justReturn( '<meta name="generator" content="WordPress 6.9" />' );
	}

	public function test_identity(): void {
		$check = new WpVersionHidden();

		$this->assertSame( 'wp_version_hidden', $check->id() );
		$this->assertSame( 'security', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_good_when_the_generator_filter_empties_output(): void {
		Filters\expectApplied( 'the_generator' )->once()->andReturn( '' );

		$this->assertSame( 'good', ( new WpVersionHidden() )->run()->status() );
	}

	public function test_recommended_when_generator_discloses_the_version(): void {
		// the_generator passes the real markup through by default.
		$this->assertSame( 'recommended', ( new WpVersionHidden() )->run()->status() );
	}

	public function test_good_when_generator_is_custom_without_version(): void {
		Filters\expectApplied( 'the_generator' )->once()->andReturn( '<meta name="generator" content="My CMS" />' );

		$this->assertSame( 'good', ( new WpVersionHidden() )->run()->status() );
	}
}
