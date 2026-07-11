<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Parisek\TimberKit\Health\CheckRegistry;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

class CheckRegistryTest extends HealthTestCase {

	private function fakeCheck( string $id ): HealthCheck {
		return new class( $id ) implements HealthCheck {
			public function __construct( private readonly string $id ) {
			}
			public function id(): string {
				return $this->id;
			}
			public function label(): string {
				return 'Fake ' . $this->id;
			}
			public function category(): string {
				return 'security';
			}
			public function method(): string {
				return self::METHOD_EFFECT;
			}
			public function run(): Result {
				return Result::good( 'ok' );
			}
		};
	}

	public function test_all_returns_checks_keyed_by_id_in_insertion_order(): void {
		$registry = new CheckRegistry();
		$first    = $this->fakeCheck( 'first' );
		$second   = $this->fakeCheck( 'second' );

		$registry->add( $first );
		$registry->add( $second );

		$this->assertSame( [ 'first' => $first, 'second' => $second ], $registry->all() );
	}

	public function test_duplicate_id_throws(): void {
		$registry = new CheckRegistry();
		$registry->add( $this->fakeCheck( 'dup' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'dup' );

		$registry->add( $this->fakeCheck( 'dup' ) );
	}

	public function test_empty_registry_returns_empty_array(): void {
		$this->assertSame( [], ( new CheckRegistry() )->all() );
	}
}
