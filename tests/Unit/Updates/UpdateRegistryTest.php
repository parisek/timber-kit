<?php

declare(strict_types=1);

namespace Tests\Unit\Updates;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Updates\UpdateRegistry;
use PHPUnit\Framework\TestCase;

class UpdateRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_reads_applied_registry_and_marks_with_injected_clock(): void {
		$stored = [ 'theme:0001' => [ 'applied' => '2026-07-14T10:00:00+00:00', 'duration_ms' => 12 ] ];
		$writes = [];

		Functions\when( 'get_option' )->alias( fn () => $stored );
		Functions\when( 'update_option' )->alias(
			function ( string $key, array $value, bool $autoload ) use ( &$writes ): bool {
				$writes[] = compact( 'key', 'value', 'autoload' );
				return true;
			}
		);

		$registry = new UpdateRegistry( static fn (): \DateTimeImmutable => new \DateTimeImmutable( '2026-07-14 12:34:56', new \DateTimeZone( 'UTC' ) ) );

		$this->assertTrue( $registry->isApplied( 'theme:0001' ) );
		$this->assertFalse( $registry->isApplied( 'theme:0002' ) );

		$registry->markApplied( 'theme:0002', 34 );

		$this->assertSame( 'timber_kit_updates_applied', $writes[0]['key'] );
		$this->assertFalse( $writes[0]['autoload'] );
		$this->assertSame( '2026-07-14T12:34:56+00:00', $writes[0]['value']['theme:0002']['applied'] );
		$this->assertSame( 34, $writes[0]['value']['theme:0002']['duration_ms'] );
	}
}
