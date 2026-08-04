<?php

declare(strict_types=1);

namespace Tests\Unit\Acfml;

use Parisek\TimberKit\Acfml\LoadReferenceGuard;
use PHPUnit\Framework\TestCase;

class LoadReferenceGuardTest extends TestCase {

	private \WP_Hook $hook;

	protected function setUp(): void {
		parent::setUp();

		$this->hook = new \WP_Hook();

		$GLOBALS['wp_filter'] = [ LoadReferenceGuard::HOOK => $this->hook ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_filter'] );

		parent::tearDown();
	}

	/** Simulates one ACFML sync: the closure is added and never removed. */
	private function leakOne(): void {
		$this->hook->add_filter( LoadReferenceGuard::HOOK, static fn( $reference ) => $reference );
	}

	public function test_removes_closures_added_after_construction(): void {
		$guard = new LoadReferenceGuard();

		$this->leakOne();
		$this->leakOne();

		$this->assertSame( 2, $guard->sweep() );
		$this->assertSame( 0, $this->hook->count() );
	}

	public function test_preserves_callbacks_registered_before_construction(): void {
		$theme_filter = static fn( $reference ) => $reference;
		$this->hook->add_filter( LoadReferenceGuard::HOOK, $theme_filter );

		$guard = new LoadReferenceGuard();
		$this->leakOne();

		$this->assertSame( 1, $guard->sweep(), 'only the leaked closure is removed' );
		$this->assertSame( 1, $this->hook->count() );
		$this->assertSame(
			$theme_filter,
			$this->hook->callbacks[10][ spl_object_hash( $theme_filter ) ]['function'],
			'the pre-existing theme filter survives'
		);
	}

	public function test_leaves_named_callbacks_alone(): void {
		$guard = new LoadReferenceGuard();

		// A plugin registering a named function mid-loop is a deliberate act,
		// not a leak — the guard must not undo it.
		$this->hook->add_filter( LoadReferenceGuard::HOOK, 'strtolower' );

		$this->assertSame( 0, $guard->sweep() );
		$this->assertSame( 1, $this->hook->count() );
	}

	public function test_sweeps_every_priority(): void {
		$guard = new LoadReferenceGuard();

		$this->hook->add_filter( LoadReferenceGuard::HOOK, static fn( $r ) => $r, 5 );
		$this->hook->add_filter( LoadReferenceGuard::HOOK, static fn( $r ) => $r, 10 );
		$this->hook->add_filter( LoadReferenceGuard::HOOK, static fn( $r ) => $r, 99 );

		$this->assertSame( 3, $guard->sweep() );
		$this->assertSame( 0, $this->hook->count() );
	}

	public function test_sweep_is_a_no_op_once_upstream_stops_leaking(): void {
		$guard = new LoadReferenceGuard();

		$this->assertSame( 0, $guard->sweep() );
		$this->assertSame( 0, $guard->sweptTotal() );
	}

	public function test_tolerates_the_hook_never_having_been_registered(): void {
		$GLOBALS['wp_filter'] = [];

		$guard = new LoadReferenceGuard();

		$this->assertSame( 0, $guard->sweep(), 'no hook object yet is the normal pre-ACFML state' );
	}

	public function test_accumulates_a_running_total_across_sweeps(): void {
		$guard = new LoadReferenceGuard();

		$this->leakOne();
		$guard->sweep();
		$this->leakOne();
		$this->leakOne();
		$guard->sweep();

		$this->assertSame( 3, $guard->sweptTotal() );
	}

	public function test_baseline_is_taken_at_construction_not_at_first_sweep(): void {
		$this->leakOne();

		// Anything already present when the guard is built is somebody else's;
		// a guard constructed late must not clean up before its own watch.
		$guard = new LoadReferenceGuard();

		$this->assertSame( 0, $guard->sweep() );
		$this->assertSame( 1, $this->hook->count() );
	}

	public function test_around_sweeps_even_when_the_work_throws(): void {
		$guard = new LoadReferenceGuard();

		try {
			$guard->around( function (): void {
				$this->leakOne();
				throw new \RuntimeException( 'sync failed' );
			} );
			$this->fail( 'the exception must propagate' );
		} catch ( \RuntimeException ) {
			// expected
		}

		$this->assertSame( 0, $this->hook->count(), 'a failed sync must not leave the leak behind' );
		$this->assertSame( 1, $guard->sweptTotal() );
	}

	public function test_around_returns_the_work_result(): void {
		$guard = new LoadReferenceGuard();

		$this->assertSame( 'ok', $guard->around( static fn (): string => 'ok' ) );
	}
}
