<?php

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

abstract class WpmlBlockOverrideTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset static memo properties on the class under test between tests.
		$this->resetMemo( 'sourceBlocksMemo', [] );
		$this->resetMemo( 'copyFieldsIndex', null );
		$this->resetMemo( 'blockOrdinals', [] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resetMemo( string $property, mixed $value ): void {
		$ref = new \ReflectionProperty( \Parisek\TimberKit\WpmlBlockOverride::class, $property );
		$ref->setValue( null, $value );
	}

	protected static function callPrivate( string $method, array $args ): mixed {
		$ref = new \ReflectionMethod( \Parisek\TimberKit\WpmlBlockOverride::class, $method );
		return $ref->invoke( null, ...$args );
	}
}
