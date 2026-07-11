<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\FileEditingDisabled;
use Parisek\TimberKit\Health\HealthCheck;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Unit\Health\HealthTestCase;

class FileEditingDisabledTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_identity(): void {
		$check = new FileEditingDisabled();

		$this->assertSame( 'file_editing_disabled', $check->id() );
		$this->assertSame( 'security', $check->category() );
		$this->assertSame( HealthCheck::METHOD_CONFIG, $check->method() );
	}

	// DISALLOW_FILE_EDIT is process-global and other suite tests may define
	// it — both run() paths isolate in their own process to stay order-proof.

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_recommended_when_constant_undefined(): void {
		$this->assertSame( 'recommended', ( new FileEditingDisabled() )->run()->status() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_good_when_constant_true(): void {
		define( 'DISALLOW_FILE_EDIT', true );

		$this->assertSame( 'good', ( new FileEditingDisabled() )->run()->status() );
	}
}
