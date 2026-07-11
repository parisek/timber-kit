<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\XmlrpcDisabled;
use Parisek\TimberKit\Health\HealthCheck;
use Tests\Unit\Health\HealthTestCase;

class XmlrpcDisabledTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_identity(): void {
		$check = new XmlrpcDisabled();

		$this->assertSame( 'xmlrpc_disabled', $check->id() );
		$this->assertSame( 'security', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_good_when_xmlrpc_filter_returns_false(): void {
		Filters\expectApplied( 'xmlrpc_enabled' )->once()->with( true )->andReturn( false );

		$this->assertSame( 'good', ( new XmlrpcDisabled() )->run()->status() );
	}

	public function test_recommended_when_xmlrpc_stays_enabled(): void {
		// Brain\Monkey's apply_filters passes the value through by default.
		$this->assertSame( 'recommended', ( new XmlrpcDisabled() )->run()->status() );
	}
}
