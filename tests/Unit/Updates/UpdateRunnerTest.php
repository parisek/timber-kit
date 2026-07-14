<?php

declare(strict_types=1);

namespace Tests\Unit\Updates;

use Parisek\TimberKit\Updates\DiscoveryResult;
use Parisek\TimberKit\Updates\AppliedUpdatesRegistry;
use Parisek\TimberKit\Updates\UpdateRunner;
use PHPUnit\Framework\TestCase;

class UpdateRunnerTest extends TestCase {

	public function test_runs_pending_in_order_and_marks_successes(): void {
		$events = [];
		$updates = [
			$this->update( 'card:0002', static function () use ( &$events ): void { $events[] = 'card:0002'; } ),
			$this->update( 'alert:0001', static function () use ( &$events ): void { $events[] = 'alert:0001'; } ),
		];
		$runner = new UpdateRunner(
			static fn (): DiscoveryResult => new DiscoveryResult( $updates ),
			new MemoryRegistry()
		);

		$result = $runner->run();

		$this->assertSame( [ 'alert:0001', 'card:0002' ], $events );
		$this->assertSame( [ 'alert:0001', 'card:0002' ], array_column( $result['executed'], 'id' ) );
	}

	public function test_skips_applied_and_respects_component_and_only_scopes(): void {
		$registry = new MemoryRegistry( [ 'card:0001' => [ 'applied' => 'then', 'duration_ms' => 1 ] ] );
		$runner   = new UpdateRunner(
			fn (): DiscoveryResult => new DiscoveryResult( [
				$this->update( 'card:0001', static function (): void {} ),
				$this->update( 'card:0002', static function (): void {} ),
				$this->update( 'hero:0001', static function (): void {} ),
			] ),
			$registry
		);

		$result = $runner->run( component: 'card', only: 'card:0001' );

		$this->assertSame( [], $result['executed'] );
		$this->assertSame( [ 'card:0001', 'card:0002', 'hero:0001' ], array_column( $result['skipped'], 'id' ) );
	}

	public function test_failure_aborts_rest_and_does_not_mark_failed_update(): void {
		$events = [];
		$registry = new MemoryRegistry();
		$updates = [
			$this->update( 'card:0001', static function () use ( &$events ): void { $events[] = 'first'; throw new \RuntimeException( 'boom' ); } ),
			$this->update( 'card:0002', static function () use ( &$events ): void { $events[] = 'second'; } ),
		];
		$runner = new UpdateRunner(
			static fn (): DiscoveryResult => new DiscoveryResult( $updates ),
			$registry,
			static function (): void {}
		);

		$result = $runner->run();

		$this->assertSame( [ 'first' ], $events );
		$this->assertSame( 'card:0001', $result['failed'][0]['id'] );
		$this->assertFalse( $registry->isApplied( 'card:0001' ) );
	}

	public function test_dry_run_marks_nothing(): void {
		$registry = new MemoryRegistry();
		$runner = new UpdateRunner(
			fn (): DiscoveryResult => new DiscoveryResult( [
				$this->update( 'card:0001', static function (): void {} ),
			] ),
			$registry
		);

		$runner->run( dryRun: true );

		$this->assertFalse( $registry->isApplied( 'card:0001' ) );
	}

	/**
	 * @return array{id: string, component: string, number: int, description: string, path: string, run: callable}
	 */
	private function update( string $id, callable $run ): array {
		[ $component, $number ] = explode( ':', $id );

		return [
			'id'          => $id,
			'component'   => $component,
			'number'      => (int) $number,
			'description' => $id,
			'path'        => '/tmp/' . $id . '.php',
			'run'         => $run,
		];
	}
}

class MemoryRegistry implements AppliedUpdatesRegistry {

	/** @param array<string, array{applied: string, duration_ms: int}> $applied */
	public function __construct( private array $applied = [] ) {}

	public function isApplied( string $id ): bool {
		return isset( $this->applied[ $id ] );
	}

	public function markApplied( string $id, int $duration_ms ): void {
		$this->applied[ $id ] = [ 'applied' => 'now', 'duration_ms' => $duration_ms ];
	}

	/** @return array<string, array{applied: string, duration_ms: int}> */
	public function all(): array {
		return $this->applied;
	}
}
